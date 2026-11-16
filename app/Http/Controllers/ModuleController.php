<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\WorkOrder;
use App\Services\FacilityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Landing page for modules whose full UI is not built yet. Access is already enforced by the
 * route's `can:` middleware; this only renders an honest empty state with real record counts.
 */
class ModuleController extends Controller
{
    public function show(Request $request, string $module): View
    {
        $config = config("kbt.modules.$module");
        abort_unless($config, 404);
        [$label, , , $icon, $description] = $config;

        $ids = FacilityContext::ids($request->user());
        $count = match ($module) {
            'assets' => Asset::query()->inFacilities($ids)->count(),
            'work-orders' => WorkOrder::query()->inFacilities($ids)->count(),
            default => null,
        };

        return view('module', compact('module', 'label', 'icon', 'description', 'count'));
    }
}
