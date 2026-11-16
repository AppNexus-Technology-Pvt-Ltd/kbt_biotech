<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetStatusChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The five asset reports. Each returns the same shape so the screen, the CSV export and the tests share one source:
 * ['title', 'columns' => [key => label], 'query' => Builder, 'map' => callable(model): array, 'summary' => [...]]
 * Every query is limited to the caller's facilities.
 */
class AssetReportService
{
    public const TYPES = [
        'register' => ['Asset Register Report', 'Complete list of assets with details'],
        'department' => ['Asset by Department Report', 'Department-wise asset count and value'],
        'warranty' => ['Warranty & AMC Expiry Report', 'Assets with warranty/AMC expiring within the selected period'],
        'location' => ['Asset Location Report', 'Assets by facility, building, floor and room'],
        'lifecycle' => ['Asset Lifecycle Report', 'Operational, under maintenance, out of service, retired and disposed assets'],
    ];

    private function base(User $user, Request $r): Builder
    {
        $q = Asset::query()->inFacilities(FacilityContext::ids($user))
            ->with(['facility:id,name', 'categoryRecord:id,name', 'department:id,name', 'location.building:id,name', 'manufacturerRecord:id,name']);
        if ($r->filled('facility_id')) {
            abort_unless($user->canAccessFacility($r->integer('facility_id')), 403, 'You do not have access to this facility.');
            $q->where('assets.facility_id', $r->integer('facility_id'));
        }

        return $q;
    }

    /** @return array{title:string,columns:array<string,string>,query:Builder,map:callable,summary:array<string,mixed>} */
    public function build(string $type, User $user, Request $r): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $q = $this->base($user, $r);

        return match ($type) {
            'register' => $this->register($q, $r),
            'department' => $this->department($user, $r),
            'warranty' => $this->warranty($q, $r),
            'location' => $this->location($q),
            'lifecycle' => $this->lifecycle($q, $r),
        };
    }

    private function register(Builder $q, Request $r): array
    {
        if (array_key_exists($r->input('status'), Asset::STATUSES)) {
            $q->where('status', $r->input('status'));
        }
        if ($r->filled('category_id')) {
            $q->where('category_id', $r->integer('category_id'));
        }

        return ['title' => self::TYPES['register'][0], 'query' => $q->orderBy('asset_tag'), 'summary' => [],
            'columns' => ['asset_tag' => 'Asset ID', 'name' => 'Equipment Name', 'category' => 'Category', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'serial' => 'Serial Number',
                'facility' => 'Facility', 'department' => 'Department', 'location' => 'Location', 'status' => 'Status', 'purchase_date' => 'Purchase Date', 'cost' => 'Cost (INR)'],
            'map' => fn (Asset $a) => ['asset_tag' => $a->asset_tag, 'name' => $a->name, 'category' => $a->categoryRecord?->name ?? $a->category,
                'manufacturer' => $a->manufacturerRecord?->name ?? $a->manufacturer, 'model' => $a->model, 'serial' => $a->serial_number, 'facility' => $a->facility?->name,
                'department' => $a->department?->name, 'location' => $a->location?->name, 'status' => Asset::STATUSES[$a->status] ?? $a->status,
                'purchase_date' => $a->purchase_date?->format('Y-m-d'), 'cost' => $a->purchase_cost]];
    }

    /** Aggregated: one row per department, live assets only. */
    private function department(User $user, Request $r): array
    {
        $q = Asset::query()->inFacilities(FacilityContext::ids($user))->live()
            ->leftJoin('departments as d', 'd.id', '=', 'assets.department_id')
            ->leftJoin('facilities as f', 'f.id', '=', 'assets.facility_id')
            ->selectRaw("coalesce(d.name, 'No department') as department, f.name as facility, count(*) as assets, coalesce(sum(assets.purchase_cost), 0) as value")
            ->groupBy('d.id', 'd.name', 'f.id', 'f.name')->orderBy('f.name')->orderBy('d.name');
        if ($r->filled('facility_id')) {
            abort_unless($user->canAccessFacility($r->integer('facility_id')), 403, 'You do not have access to this facility.');
            $q->where('assets.facility_id', $r->integer('facility_id'));
        }

        return ['title' => self::TYPES['department'][0], 'query' => $q, 'summary' => [],
            'columns' => ['facility' => 'Facility', 'department' => 'Department', 'assets' => 'Assets', 'value' => 'Total Value (INR)'],
            'map' => fn ($row) => ['facility' => $row->facility, 'department' => $row->department, 'assets' => (int) $row->assets, 'value' => number_format((float) $row->value, 2, '.', '')]];
    }

    private function warranty(Builder $q, Request $r): array
    {
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : now()->startOfDay();
        $to = $r->filled('to') ? Carbon::parse($r->input('to'))->endOfDay() : now()->addDays(90)->endOfDay();
        abort_if($to->lt($from), 422, 'The end date cannot be before the start date.');
        $range = [$from->toDateString(), $to->toDateString()];

        $q->live()->where(fn ($w) => $w->whereBetween('warranty_expiry_at', $range)->orWhereBetween('amc_expiry_at', $range))
            ->orderBy('warranty_expiry_at')->orderBy('amc_expiry_at')->orderBy('asset_tag');
        $days = fn (?Carbon $d) => $d ? (int) now()->startOfDay()->diffInDays($d, false) : null;

        return ['title' => self::TYPES['warranty'][0], 'query' => $q, 'summary' => ['period' => $range[0].' to '.$range[1]],
            'columns' => ['asset_tag' => 'Asset ID', 'name' => 'Equipment Name', 'facility' => 'Facility', 'department' => 'Department', 'warranty' => 'Warranty Expiry',
                'warranty_days' => 'Warranty Days Left', 'amc' => 'AMC Expiry', 'amc_days' => 'AMC Days Left', 'amc_vendor' => 'AMC Vendor'],
            'map' => fn (Asset $a) => ['asset_tag' => $a->asset_tag, 'name' => $a->name, 'facility' => $a->facility?->name, 'department' => $a->department?->name,
                'warranty' => $a->warranty_expiry_at?->format('Y-m-d'), 'warranty_days' => $days($a->warranty_expiry_at),
                'amc' => $a->amc_expiry_at?->format('Y-m-d'), 'amc_days' => $days($a->amc_expiry_at), 'amc_vendor' => $a->amc_vendor]];
    }

    private function location(Builder $q): array
    {
        $q->live()->orderBy('facility_id')->orderBy('location_id')->orderBy('asset_tag');

        return ['title' => self::TYPES['location'][0], 'query' => $q, 'summary' => [],
            'columns' => ['facility' => 'Facility', 'building' => 'Building', 'floor' => 'Floor', 'room' => 'Room / Location', 'asset_tag' => 'Asset ID', 'name' => 'Equipment Name', 'status' => 'Status'],
            'map' => fn (Asset $a) => ['facility' => $a->facility?->name, 'building' => $a->location?->building?->name, 'floor' => $a->location?->floor,
                'room' => $a->location?->name, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'status' => Asset::STATUSES[$a->status] ?? $a->status]];
    }

    private function lifecycle(Builder $q, Request $r): array
    {
        $counts = (clone $q)->reorder()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        if (array_key_exists($r->input('status'), Asset::STATUSES)) {
            $q->where('status', $r->input('status'));
        }
        $lastChange = AssetStatusChange::selectRaw('asset_id, max(effective_date) d')->groupBy('asset_id')->pluck('d', 'asset_id');

        return ['title' => self::TYPES['lifecycle'][0], 'query' => $q->orderBy('status')->orderBy('asset_tag'),
            'summary' => collect(Asset::STATUSES)->map(fn ($label, $key) => ['label' => $label, 'count' => (int) ($counts[$key] ?? 0)])->values()->all(),
            'columns' => ['asset_tag' => 'Asset ID', 'name' => 'Equipment Name', 'facility' => 'Facility', 'status' => 'Status', 'purchase_date' => 'Purchase Date', 'age' => 'Age (years)', 'last_change' => 'Last Status Change'],
            'map' => fn (Asset $a) => ['asset_tag' => $a->asset_tag, 'name' => $a->name, 'facility' => $a->facility?->name, 'status' => Asset::STATUSES[$a->status] ?? $a->status,
                'purchase_date' => $a->purchase_date?->format('Y-m-d'), 'age' => $a->purchase_date ? round($a->purchase_date->floatDiffInYears(now()), 1) : null,
                'last_change' => $lastChange[$a->id] ?? null]];
    }
}
