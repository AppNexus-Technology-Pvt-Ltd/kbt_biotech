<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AssetImportService;
use App\Services\AssetReportService;
use App\Services\AuditLogger;
use App\Services\FacilityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** QR / barcode labels, scan lookup, bulk import and reports (Step 3, screens 5, 6, 7, 11). */
class AssetToolsController extends Controller
{
    private function scoped(Request $request)
    {
        return Asset::query()->inFacilities(FacilityContext::ids($request->user()));
    }

    /* --------------------------------------------------------------- labels */

    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'preview' => ['sometimes', 'boolean'],
        ]);

        $assets = $this->scoped($request)->with(['department:id,name', 'location:id,name'])->whereIn('id', $data['ids'])->orderBy('asset_tag')->get();
        if ($assets->count() !== count($data['ids'])) {
            throw new WorkflowException('Some of the selected assets were not found or are not in your facilities.');
        }
        if (! $request->boolean('preview')) AuditLogger::log('asset.labels_generated', $request->user(), null, null, ['assets' => $assets->count(), 'copies' => $data['copies'] ?? 1]);

        return response()->json(['success' => true, 'data' => $assets->map(fn ($a) => [
            'id' => $a->id, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'serial_number' => $a->serial_number,
            'department' => $a->department?->name, 'location' => $a->location?->name,
            'scan_url' => route('assets.scan', ['code' => $a->asset_tag]),
        ])->values()]);
    }

    /* ---------------------------------------------------------------- scan */

    public function lookup(Request $request): JsonResponse
    {
        $code = $this->extractCode((string) $request->input('code'));
        if ($code === '') {
            throw new WorkflowException('Scan or type an asset ID.');
        }
        $asset = $this->scoped($request)->with(['facility:id,name', 'location:id,name', 'department:id,name', 'manufacturerRecord:id,name'])->where('asset_tag', $code)->first();
        if (! $asset) {
            throw new WorkflowException("No asset found for “{$code}”, or it is not in your facilities.", 404);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'name' => $asset->name,
            'manufacturer' => $asset->manufacturerRecord?->name ?? $asset->manufacturer, 'model' => $asset->model,
            'status' => $asset->status, 'status_label' => Asset::STATUSES[$asset->status] ?? $asset->status,
            'location' => collect([$asset->facility?->name, $asset->location?->name])->filter()->implode(' › '),
            'department' => $asset->department?->name, 'image_url' => $asset->image_path ? route('assets.image', $asset) : null,
            'url' => route('assets.show', $asset),
        ]]);
    }

    /** A scanned QR holds our scan URL (…?code=BIO-0001); a barcode or typed entry is the bare tag. */
    private function extractCode(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('#^https?://#i', $raw)) {
            parse_str((string) parse_url($raw, PHP_URL_QUERY), $query);
            $raw = (string) ($query['code'] ?? '');
        }

        return mb_substr(trim($raw), 0, 60);
    }

    /* --------------------------------------------------------------- import */

    public function importTemplate(AssetImportService $import): BinaryFileResponse
    {
        return response()->download($import->templatePath(), 'asset-import-template.xlsx')->deleteFileAfterSend();
    }

    public function importValidate(Request $request, AssetImportService $import): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv']], [
            'file.extensions' => 'Upload an .xlsx or .csv file (use the template).', 'file.max' => 'The file must be 5 MB or smaller.',
        ]);

        $rows = $import->parse($request->file('file'));
        $result = $import->validate($request->user(), $rows);
        $invalid = count($result['errors']);

        return response()->json(['success' => true, 'data' => [
            'total' => count($rows), 'valid' => count($result['valid']), 'invalid' => $invalid,
            'errors' => collect($result['errors'])->take(100)->map(fn ($m, $row) => ['row' => $row, 'messages' => $m])->values(),
            'token' => $invalid === 0 ? $import->stash($request->user(), $rows) : null,
        ]]);
    }

    public function importConfirm(Request $request, AssetImportService $import): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:64']]);
        $created = $import->commit($request->user(), $request->input('token'));

        return response()->json(['success' => true, 'message' => "$created asset(s) imported.", 'data' => ['created' => $created]]);
    }

    /* -------------------------------------------------------------- reports */

    public function report(Request $request, string $type, AssetReportService $reports): JsonResponse|StreamedResponse
    {
        $r = $reports->build($type, $request->user(), $request);
        AuditLogger::log('asset.report_generated', $request->user(), null, null, ['report' => $type, 'format' => $request->input('format', 'json')]);

        if ($request->input('format') === 'csv') {
            return response()->streamDownload(function () use ($r) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_values($r['columns']));
                foreach ($r['query']->cursor() as $model) {
                    fputcsv($out, array_map([$this, 'csvSafe'], array_values(array_replace(array_fill_keys(array_keys($r['columns']), null), ($r['map'])($model)))));
                }
                fclose($out);
            }, str_replace('_', '-', $type).'-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $limit = 200;
        $total = $type === 'department' ? $r['query']->get()->count() : (clone $r['query'])->toBase()->getCountForPagination();
        $rows = $r['query']->limit($limit)->get()->map(fn ($m) => ($r['map'])($m))->values();

        return response()->json(['success' => true, 'data' => [
            'title' => $r['title'], 'columns' => collect($r['columns'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'rows' => $rows, 'total' => $total, 'shown' => $rows->count(), 'summary' => $r['summary'],
        ]]);
    }

    /** Neutralise spreadsheet formula injection in exported text. */
    private function csvSafe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
