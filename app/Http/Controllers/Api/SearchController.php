<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\WorkOrder;
use App\Services\FacilityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }
        // Escape LIKE wildcards with an explicit ESCAPE char so behaviour is identical on MySQL and SQLite.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
        $like = fn ($w, string $col, bool $or) => $w->{$or ? 'orWhereRaw' : 'whereRaw'}("$col LIKE ? ESCAPE '!'", [$term]);
        $user = $request->user();
        $ids = FacilityContext::ids($user);
        $results = [];

        if ($user->can('assets.view')) {
            Asset::query()->inFacilities($ids)
                ->where(fn ($w) => $like($like($like($w, 'name', false), 'asset_tag', true), 'serial_number', true))
                ->limit(6)->get(['id', 'asset_tag', 'name'])
                ->each(function ($a) use (&$results) {
                    $results[] = ['type' => 'Asset', 'title' => $a->name, 'subtitle' => $a->asset_tag, 'url' => url('/assets').'?q='.urlencode($a->asset_tag)];
                });
        }
        if ($user->can('work_orders.view')) {
            WorkOrder::query()->inFacilities($ids)
                ->where(fn ($w) => $like($like($w, 'number', false), 'title', true))
                ->limit(6)->get(['id', 'number', 'title'])
                ->each(function ($w) use (&$results) {
                    $results[] = ['type' => 'Work order', 'title' => $w->title, 'subtitle' => $w->number, 'url' => url('/work-orders').'?q='.urlencode($w->number)];
                });
        }

        return response()->json(['success' => true, 'data' => $results]);
    }
}
