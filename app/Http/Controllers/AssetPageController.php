<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Facility;
use App\Services\FacilityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Blade pages for the Asset Registry. Data is loaded through Api\AssetController. */
class AssetPageController extends Controller
{
    private function accessible(Request $request, Asset $asset): Asset
    {
        abort_unless($request->user()->canAccessFacility($asset->facility_id), 404);

        return $asset;
    }


    public function labels(): View
    {
        return view('assets.labels');
    }

    public function scan(): View
    {
        return view('assets.scan');
    }

    public function import(): View
    {
        return view('assets.import');
    }

    public function reports(): View
    {
        return view('assets.reports', ['types' => \App\Services\AssetReportService::TYPES]);
    }

    public function transfers(): View
    {
        return view('assets.transfers');
    }

    public function lifecycle(): View
    {
        return view('assets.lifecycle');
    }

    public function transferForm(Request $request, Asset $asset): View
    {
        $this->accessible($request, $asset);
        abort_if($asset->isClosed(), 403, 'Retired or disposed assets cannot be transferred.');

        return view('assets.transfer-form', ['asset' => $asset]);
    }

    public function statusForm(Request $request, Asset $asset): View
    {
        $this->accessible($request, $asset);
        abort_if($asset->isClosed(), 403, 'Retired or disposed assets cannot change status.');

        return view('assets.status-form', ['asset' => $asset]);
    }

    public function disposeForm(Request $request, Asset $asset): View
    {
        $this->accessible($request, $asset);
        abort_if($asset->status === 'disposed', 403, 'This asset has already been disposed.');

        return view('assets.dispose-form', ['asset' => $asset]);
    }
    public function dashboard(): View
    {
        return view('assets.dashboard');
    }

    public function register(): View
    {
        return view('assets.register');
    }

    public function create(Request $request): View
    {
        return view('assets.form', ['asset' => null, 'selectedFacility' => FacilityContext::selectedId($request->user())]);
    }

    public function edit(Request $request, Asset $asset): View
    {
        $this->accessible($request, $asset);
        abort_if($asset->isClosed(), 403, 'Retired or disposed assets cannot be edited.');

        return view('assets.form', ['asset' => $asset, 'selectedFacility' => $asset->facility_id]);
    }

    public function show(Request $request, Asset $asset): View
    {
        return view('assets.show', ['asset' => $this->accessible($request, $asset)->load('facility:id,name')]);
    }

    public function image(Request $request, Asset $asset): StreamedResponse
    {
        $this->accessible($request, $asset);
        abort_unless($asset->image_path && Storage::disk('local')->exists($asset->image_path), 404);

        return Storage::disk('local')->response($asset->image_path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function downloadDocument(Request $request, Asset $asset, int $document): StreamedResponse
    {
        $this->accessible($request, $asset);
        $doc = $asset->documents()->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($doc->file_path), 404);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
