<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Services\AuditLogger;
use App\Services\FacilityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Asset registry API. Route middleware enforces assets.* permissions; facility access is enforced here. */
class AssetController extends Controller
{
    private const RELATIONS = ['facility:id,name', 'categoryRecord:id,name', 'manufacturerRecord:id,name', 'department:id,name', 'location.building:id,name'];

    private function scoped(Request $request): Builder
    {
        return Asset::query()->inFacilities(FacilityContext::ids($request->user()));
    }

    private function find(Request $request, int $id): Asset
    {
        $asset = Asset::with(self::RELATIONS)->findOrFail($id);
        abort_unless($request->user()->canAccessFacility($asset->facility_id), 404);

        return $asset;
    }

    /* ----------------------------------------------------------- dashboard */

    public function dashboard(Request $request): JsonResponse
    {
        $live = fn () => $this->scoped($request)->live();
        $total = $live()->count();
        $byStatus = $live()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $stat = fn (string $s) => ['value' => (int) ($byStatus[$s] ?? 0), 'percent' => $total ? (int) round(($byStatus[$s] ?? 0) / $total * 100) : 0];

        $cats = $live()->leftJoin('equipment_categories as ec', 'ec.id', '=', 'assets.category_id')
            ->selectRaw("coalesce(ec.name, nullif(assets.category, ''), 'Uncategorized') as label, count(*) as c")
            ->groupBy('label')->orderByDesc('c')->get();
        $top = $cats->take(5)->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->c])->values();
        if ($cats->count() > 5) {
            $top->push(['label' => 'Others', 'count' => (int) $cats->slice(5)->sum('c')]);
        }

        return response()->json(['success' => true, 'data' => [
            'total' => ['value' => $total, 'new_this_month' => $live()->where('created_at', '>=', now()->startOfMonth())->count()],
            'operational' => $stat('operational'),
            'under_maintenance' => $stat('under_maintenance'),
            'out_of_service' => $stat('out_of_service'),
            'categories' => $top,
        ]]);
    }

    /* ---------------------------------------------------------------- list */

    private function filtered(Request $request): Builder
    {
        $query = $this->scoped($request)->with(self::RELATIONS);

        if ($request->filled('facility_id')) {
            abort_unless($request->user()->canAccessFacility($request->integer('facility_id')), 403, 'You do not have access to this facility.');
            $query->where('facility_id', $request->integer('facility_id'));
        }
        if ($term = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like)
                ->orWhere('serial_number', 'like', $like)->orWhere('model', 'like', $like));
        }
        foreach (['category_id', 'department_id', 'location_id'] as $col) {
            if ($request->filled($col)) {
                $query->where($col, $request->integer($col));
            }
        }
        if (array_key_exists($request->input('status'), Asset::STATUSES)) {
            $query->where('status', $request->input('status'));
        }

        return $query->orderBy('asset_tag');
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($request)->paginate(min(max($request->integer('per_page', 10), 5), 50));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($a) => $this->listRow($a))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request);
        AuditLogger::log('asset.exported', $request->user());

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
            fputcsv($out, ['Asset ID', 'Equipment Name', 'Category', 'Manufacturer', 'Model', 'Serial Number', 'Facility', 'Department', 'Location', 'Status', 'Purchase Date', 'Cost (INR)', 'Warranty Expiry', 'AMC Expiry']);
            $query->chunk(500, function ($assets) use ($out) {
                foreach ($assets as $a) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $a->asset_tag, $a->name, $a->categoryRecord?->name ?? $a->category, $a->manufacturerRecord?->name ?? $a->manufacturer, $a->model,
                        $a->serial_number, $a->facility?->name, $a->department?->name, $this->locationPath($a), Asset::STATUSES[$a->status] ?? $a->status,
                        $a->purchase_date?->format('Y-m-d'), $a->purchase_cost, $a->warranty_expiry_at?->format('Y-m-d'), $a->amc_expiry_at?->format('Y-m-d'),
                    ]));
                }
            });
            fclose($out);
        }, 'asset-register-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection in exported text. */
    private function csvSafe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    public function nextTag(): JsonResponse
    {
        $max = Asset::where('asset_tag', 'like', 'BIO-%')->pluck('asset_tag')
            ->map(fn ($t) => preg_match('/^BIO-(\d+)$/', $t, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return response()->json(['success' => true, 'data' => ['asset_tag' => sprintf('BIO-%04d', $max + 1)]]);
    }

    /** Dropdown data for the asset form / filters. */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $facilityId = $request->integer('facility_id') ?: null;
        if ($facilityId) {
            abort_unless($user->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
        }
        $facilities = $user->accessibleFacilities()->get(['id', 'name']);
        $scope = $facilityId ? [$facilityId] : $facilities->pluck('id')->all();

        return response()->json(['success' => true, 'data' => [
            'facilities' => $facilities,
            'categories' => EquipmentCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'manufacturers' => Manufacturer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get(['id', 'facility_id', 'name']),
            'buildings' => Building::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get(['id', 'facility_id', 'name']),
            'locations' => Location::with('building:id,name')->whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'facility_id' => $l->facility_id, 'building_id' => $l->building_id, 'name' => $this->pathOf($l)]),
        ]]);
    }

    /* ---------------------------------------------------------------- show */

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($request, $this->find($request, $id))]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);
        $logs = AuditLog::with('user:id,name')->where('subject_type', 'Asset')->where('subject_id', $asset->id)->latest('created_at')->latest('id')->limit(50)->get();

        return response()->json(['success' => true, 'data' => $logs->map(fn ($l) => [
            'action' => $l->action, 'user' => $l->user?->name ?? 'System', 'at' => $l->created_at?->toIso8601String(),
            'changes' => collect($l->new_values ?? [])->map(fn ($new, $k) => ['field' => $k, 'from' => $l->old_values[$k] ?? null, 'to' => $new])->values(),
        ])]);
    }

    public function maintenance(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);
        $orders = $asset->workOrders()->latest()->limit(25)->get(['id', 'number', 'title', 'type', 'priority', 'status', 'due_at', 'completed_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /* ----------------------------------------------------------- write ops */

    public function store(Request $request): JsonResponse
    {
        $facilityId = $request->integer('facility_id');
        abort_unless($facilityId && $request->user()->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
        $data = $this->validated($request, null, $facilityId);

        $asset = DB::transaction(function () use ($request, $data, $facilityId) {
            $payload = $this->payload($data) + ['facility_id' => $facilityId];
            if ($request->hasFile('image')) {
                $payload['image_path'] = $this->storeImage($request);
            }

            return Asset::create($payload);
        });
        AuditLogger::log('asset.created', $request->user(), $asset, null, ['asset_tag' => $asset->asset_tag, 'name' => $asset->name, 'status' => $asset->status]);

        return response()->json(['success' => true, 'message' => 'Asset created.', 'data' => $this->detail($request, $asset->load(self::RELATIONS))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);
        if ($asset->isClosed()) {
            return response()->json(['success' => false, 'message' => 'This asset is '.strtolower(Asset::STATUSES[$asset->status]).' and can no longer be edited.'], 422);
        }
        $data = $this->validated($request, $asset, $asset->facility_id);
        $payload = $this->payload($data);
        unset($payload['status']); // status changes need a reason: see AssetWorkflowController::changeStatus

        DB::transaction(function () use ($request, $asset, &$payload) {
            if ($request->hasFile('image') || $request->boolean('remove_image')) {
                $previous = $asset->image_path;
                $payload['image_path'] = $this->storeImage($request);
                if ($previous) {
                    Storage::disk('local')->delete($previous);
                }
            }
            $old = $asset->only(array_keys($payload));
            $asset->update($payload);
            $this->changes = [$old, $asset->only(array_keys($payload))];
        });
        [$old, $new] = $this->changes;
        $diff = collect($new)->filter(fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) $v)->all();
        unset($diff['image_path']);
        if ($diff) {
            AuditLogger::log('asset.updated', $request->user(), $asset, array_intersect_key($old, $diff), $diff);
        }

        return response()->json(['success' => true, 'message' => 'Asset updated.', 'data' => $this->detail($request, $asset->fresh(self::RELATIONS))]);
    }

    private array $changes = [];

    public function destroy(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);
        $orders = $asset->workOrders()->count();
        if ($orders > 0) {
            return response()->json(['success' => false, 'message' => "This asset has $orders work order(s) and cannot be deleted. Retire it instead."], 422);
        }
        $snapshot = ['asset_tag' => $asset->asset_tag, 'name' => $asset->name];
        DB::transaction(function () use ($asset) {
            foreach ($asset->documents as $doc) {
                Storage::disk('local')->delete($doc->file_path);
            }
            if ($asset->image_path) {
                Storage::disk('local')->delete($asset->image_path);
            }
            $asset->delete();
        });
        AuditLogger::log('asset.deleted', $request->user(), $asset, $snapshot);

        return response()->json(['success' => true, 'message' => 'Asset deleted.']);
    }

    /* ----------------------------------------------------------- documents */

    public function documents(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);

        return response()->json(['success' => true, 'data' => $asset->documents()->with('uploader:id,name')->latest()->get()->map(fn ($d) => $this->docRow($d))]);
    }

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $asset = $this->find($request, $id);
        $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
        ], ['file.max' => 'The file must be 10 MB or smaller.', 'file.mimes' => 'Allowed types: PDF, JPG, PNG, Word, Excel.']);

        $file = $request->file('file');
        $doc = $asset->documents()->create([
            'title' => $request->input('title'), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType(),
            'size' => $file->getSize(), 'uploaded_by' => $request->user()->id,
            'file_path' => $file->storeAs('asset-documents/'.$asset->id, Str::uuid().'.'.$file->guessExtension(), 'local'),
        ]);
        AuditLogger::log('asset.document_added', $request->user(), $asset, null, ['title' => $doc->title]);

        return response()->json(['success' => true, 'message' => 'Document uploaded.', 'data' => $this->docRow($doc->load('uploader:id,name'))], 201);
    }

    public function deleteDocument(Request $request, int $id, int $docId): JsonResponse
    {
        $asset = $this->find($request, $id);
        $doc = $asset->documents()->findOrFail($docId);
        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();
        AuditLogger::log('asset.document_removed', $request->user(), $asset, ['title' => $doc->title]);

        return response()->json(['success' => true, 'message' => 'Document removed.']);
    }

    private function docRow($d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'name' => $d->original_name, 'size' => $d->size, 'mime' => $d->mime,
            'uploaded_by' => $d->uploader?->name, 'at' => $d->created_at?->toIso8601String(),
            'url' => route('assets.documents.download', [$d->asset_id, $d->id])];
    }

    /* ------------------------------------------------------------- helpers */

    private function validated(Request $request, ?Asset $asset, int $facilityId): array
    {
        $data = $request->validate([
            'asset_tag' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('assets', 'asset_tag')->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', Rule::exists('equipment_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset?->category_id))],
            'manufacturer_id' => ['required', Rule::exists('manufacturers', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $asset?->manufacturer_id))],
            'model' => ['required', 'string', 'max:120'],
            'serial_number' => ['required', 'string', 'max:120',
                Rule::unique('assets', 'serial_number')->where('manufacturer_id', $request->input('manufacturer_id'))->ignore($asset?->id)],
            'asset_type' => ['required', Rule::in(array_keys(Asset::TYPES))],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'status' => $asset ? ['sometimes'] : ['required', Rule::in(Asset::EDITABLE_STATUSES)], // later changes go through the lifecycle workflow
            'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'funding_source' => ['nullable', 'string', 'max:120'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'specifications' => ['nullable', 'string', 'max:5000'],
            'installed_at' => ['nullable', 'date_format:Y-m-d'],
            'warranty_expiry_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
            'amc_expiry_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
            'amc_vendor' => ['nullable', 'string', 'max:160'],
            'last_pm_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'next_pm_due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_pm_at'],
            'last_calibration_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'next_calibration_due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_calibration_at'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
        ], ['image.max' => 'The image must be 2 MB or smaller.', 'serial_number.unique' => 'This serial number is already registered for this manufacturer.']);

        // department / location must belong to the asset's facility
        $errors = [];
        if (Department::find($data['department_id'])->facility_id !== $facilityId) {
            $errors['department_id'] = 'The department belongs to a different facility.';
        }
        if (Location::find($data['location_id'])->facility_id !== $facilityId) {
            $errors['location_id'] = 'The location belongs to a different facility.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /** Persisted columns; keeps the legacy free-text category / manufacturer columns in sync for older consumers. */
    private function payload(array $data): array
    {
        unset($data['image'], $data['remove_image']);
        $data['category'] = EquipmentCategory::find($data['category_id'])?->name;
        $data['manufacturer'] = Manufacturer::find($data['manufacturer_id'])?->name;

        return $data;
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }
        $file = $request->file('image');

        return $file->storeAs('asset-images', Str::uuid().'.'.$file->guessExtension(), 'local');
    }

    private function pathOf(Location $l): string
    {
        return collect([$l->building?->name, $l->floor !== null ? 'Floor '.$l->floor : null, $l->name])->filter(fn ($v) => $v !== null && $v !== '')->implode(' › ');
    }

    private function locationPath(Asset $a): ?string
    {
        return $a->location ? $this->pathOf($a->location) : null;
    }

    private function listRow(Asset $a): array
    {
        return ['id' => $a->id, 'asset_tag' => $a->asset_tag, 'name' => $a->name,
            'category' => $a->categoryRecord?->name ?? $a->category, 'department' => $a->department?->name,
            'location' => $a->location?->name, 'status' => $a->status, 'status_label' => Asset::STATUSES[$a->status] ?? $a->status,
            'url' => route('assets.show', $a), 'edit_url' => route('assets.edit', $a)];
    }

    private function detail(Request $request, Asset $a): array
    {
        $user = $request->user();
        $d = fn ($v) => $v?->format('Y-m-d');

        return $this->listRow($a) + [
            'facility_id' => $a->facility_id, 'facility' => $a->facility?->name,
            'category_id' => $a->category_id, 'manufacturer_id' => $a->manufacturer_id, 'manufacturer' => $a->manufacturerRecord?->name ?? $a->manufacturer,
            'model' => $a->model, 'serial_number' => $a->serial_number, 'asset_type' => $a->asset_type, 'asset_type_label' => Asset::TYPES[$a->asset_type] ?? $a->asset_type,
            'department_id' => $a->department_id, 'location_id' => $a->location_id, 'location_path' => $this->locationPath($a),
            'purchase_date' => $d($a->purchase_date), 'purchase_cost' => $a->purchase_cost, 'funding_source' => $a->funding_source,
            'vendor_name' => $a->vendor_name, 'invoice_number' => $a->invoice_number, 'specifications' => $a->specifications,
            'installed_at' => $d($a->installed_at), 'warranty_expiry_at' => $d($a->warranty_expiry_at), 'amc_expiry_at' => $d($a->amc_expiry_at), 'amc_vendor' => $a->amc_vendor,
            'last_pm_at' => $d($a->last_pm_at), 'next_pm_due_at' => $d($a->next_pm_due_at),
            'last_calibration_at' => $d($a->last_calibration_at), 'next_calibration_due_at' => $d($a->next_calibration_due_at),
            'remarks' => $a->remarks, 'image_url' => $a->image_path ? route('assets.image', $a) : null, 'closed' => $a->isClosed(),
            'pending_transfer' => ($t = \App\Models\AssetTransfer::where('asset_id', $a->id)->where('status', 'pending')->first())
                ? ['id' => $t->id, 'requested_date' => $t->requested_date->format('d M Y')] : null,
            'can' => [
                'edit' => $user->can('assets.edit') && ! $a->isClosed(),
                'delete' => $user->can('assets.delete'),
                'transfer' => $user->can('assets.edit') && ! $a->isClosed() && ! $t,
                'status' => $user->can('assets.edit') && ! $a->isClosed(),
                'dispose' => $user->can('assets.approve') && $a->status !== 'disposed',
            ],
        ];
    }
}
