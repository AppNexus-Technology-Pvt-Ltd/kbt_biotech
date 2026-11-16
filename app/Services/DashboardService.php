<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;

class DashboardService
{
    public const TYPES = ['preventive', 'breakdown', 'calibration', 'installation'];

    /** @return array<string,mixed> */
    public function build(User $user): array
    {
        $ids = FacilityContext::ids($user);
        $selected = FacilityContext::selectedId($user);
        $canAssets = $user->hasPermission('assets.view');
        $canWo = $user->hasPermission('work_orders.view');
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $data = [
            'facility' => $selected ? ['id' => $selected, 'name' => $user->accessibleFacilities()->whereKey($selected)->value('name')] : null,
            'permissions' => ['assets' => $canAssets, 'work_orders' => $canWo],
            'stats' => [],
            'trend' => null,
            'recent_work_orders' => [],
            'status_breakdown' => null,
            'priority_breakdown' => null,
            'pm_overview' => null,
        ];

        if ($canAssets) {
            $assets = fn () => Asset::query()->inFacilities($ids)->live();
            $data['stats']['total_equipment'] = [
                'value' => $assets()->count(),
                'note' => $assets()->where('created_at', '>=', $monthStart)->count(),
            ];
            $data['stats']['pm_due'] = [
                'value' => $assets()->whereBetween('next_pm_due_at', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
                'overdue' => $assets()->where('next_pm_due_at', '<', $now->toDateString())->count(),
            ];
            $data['stats']['calibration_due'] = [
                'value' => $assets()->whereBetween('next_calibration_due_at', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
                'overdue' => $assets()->where('next_calibration_due_at', '<', $now->toDateString())->count(),
            ];
            $data['pm_overview'] = [
                'due_this_month' => $data['stats']['pm_due']['value'],
                'overdue' => $data['stats']['pm_due']['overdue'],
                'completed_this_month' => $canWo
                    ? WorkOrder::query()->inFacilities($ids)->where('type', 'preventive')->where('status', 'completed')
                        ->whereBetween('completed_at', [$monthStart, $monthEnd])->count()
                    : null,
            ];
        }

        if ($canWo) {
            $wo = fn () => WorkOrder::query()->inFacilities($ids);
            $open = fn () => $wo()->whereIn('status', WorkOrder::OPEN_STATUSES);

            $data['stats']['open_work_orders'] = [
                'value' => $open()->count(),
                'critical' => $open()->where('priority', 'critical')->count(),
            ];

            $statusCounts = $wo()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $data['status_breakdown'] = collect(['open', 'in_progress', 'on_hold', 'completed', 'cancelled'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($statusCounts[$s] ?? 0)])->all();

            $prio = $open()->selectRaw('priority, count(*) as c')->groupBy('priority')->pluck('c', 'priority');
            $data['priority_breakdown'] = collect(['critical', 'high', 'medium', 'low'])
                ->mapWithKeys(fn ($p) => [$p => (int) ($prio[$p] ?? 0)])->all();

            $labels = [];
            $series = array_fill_keys(self::TYPES, []);
            for ($i = 5; $i >= 0; $i--) {
                $start = $now->copy()->startOfMonth()->subMonths($i);
                $end = $start->copy()->endOfMonth();
                $labels[] = $start->format('M');
                $counts = $wo()->whereBetween('created_at', [$start, $end])
                    ->selectRaw('type, count(*) as c')->groupBy('type')->pluck('c', 'type');
                foreach (self::TYPES as $t) {
                    $series[$t][] = (int) ($counts[$t] ?? 0);
                }
            }
            $data['trend'] = ['labels' => $labels, 'series' => $series];

            $data['recent_work_orders'] = $wo()->with('asset:id,name')->latest('id')->limit(8)->get()
                ->map(fn (WorkOrder $w) => [
                    'id' => $w->id,
                    'number' => $w->number,
                    'asset' => $w->asset?->name ?? $w->title,
                    'type' => $w->type,
                    'priority' => $w->priority,
                    'status' => $w->status,
                    'created_at' => $w->created_at?->toIso8601String(),
                ])->all();
        }

        return $data;
    }
}
