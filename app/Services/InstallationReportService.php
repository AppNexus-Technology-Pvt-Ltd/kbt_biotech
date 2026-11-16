<?php

namespace App\Services;

use App\Models\InstallationJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The installation reports. Every report returns ['title','columns'=>[key=>label],'rows'=>Collection,'summary'=>[...]]. */
class InstallationReportService
{
    public const TYPES = [
        'summary' => ['Installation Summary', 'Jobs by status with average time to complete'],
        'register' => ['Installation Register', 'Complete list of installations'],
        'pending' => ['Pending Installations Report', 'Installations awaiting approval'],
        'department' => ['Department-wise Installation', 'Installations by department'],
        'cost' => ['Installation Cost Report', 'Cost summary of installed equipment'],
    ];

    /** @return Collection<int,InstallationJob> */
    private function jobs(User $user, Request $r)
    {
        $q = InstallationJob::query()->inFacilities(FacilityContext::ids($user))
            ->with(['asset:id,asset_tag,name,purchase_cost', 'department:id,name', 'facility:id,name', 'engineer:id,name']);
        if ($r->filled('facility_id')) {
            abort_unless($user->canAccessFacility($r->integer('facility_id')), 403, 'You do not have access to this facility.');
            $q->where('facility_id', $r->integer('facility_id'));
        }
        if ($r->filled('department_id')) {
            $q->where('department_id', $r->integer('department_id'));
        }
        if ($r->filled('from') || $r->filled('to')) {
            $from = $r->filled('from') ? Carbon::parse($r->input('from'))->toDateString() : '0001-01-01';
            $to = $r->filled('to') ? Carbon::parse($r->input('to'))->toDateString() : '9999-12-31';
            abort_if($to < $from, 422, 'The end date cannot be before the start date.');
            $q->whereBetween('planned_date', [$from, $to]);
        }

        return $q->orderBy('job_number')->get();
    }

    public function build(string $type, User $user, Request $r): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $jobs = $this->jobs($user, $r);
        $title = self::TYPES[$type][0];

        return match ($type) {
            'register' => ['title' => $title, 'summary' => [], 'rows' => $jobs->map(fn ($j) => [
                'job' => $j->job_number, 'asset' => $j->asset?->asset_tag, 'equipment' => $j->asset?->name, 'facility' => $j->facility?->name, 'department' => $j->department?->name,
                'type' => InstallationJob::TYPES[$j->type], 'priority' => InstallationJob::PRIORITIES[$j->priority], 'planned' => $j->planned_date->format('Y-m-d'),
                'completed' => $j->completed_at?->format('Y-m-d'), 'engineer' => $j->engineer?->name, 'status' => InstallationJob::STATUSES[$j->status]])->values(),
                'columns' => ['job' => 'Job No', 'asset' => 'Asset ID', 'equipment' => 'Equipment', 'facility' => 'Facility', 'department' => 'Department', 'type' => 'Type', 'priority' => 'Priority',
                    'planned' => 'Planned Date', 'completed' => 'Completed On', 'engineer' => 'Engineer', 'status' => 'Status']],

            'pending' => ['title' => $title, 'summary' => [], 'rows' => $jobs->where('status', 'pending_approval')->map(fn ($j) => [
                'job' => $j->job_number, 'asset' => $j->asset?->asset_tag, 'equipment' => $j->asset?->name, 'department' => $j->department?->name, 'engineer' => $j->engineer?->name,
                'requested' => $j->approval_requested_at?->format('Y-m-d'), 'waiting' => $j->approval_requested_at ? (int) $j->approval_requested_at->diffInDays(now()) : null])->values(),
                'columns' => ['job' => 'Job No', 'asset' => 'Asset ID', 'equipment' => 'Equipment', 'department' => 'Department', 'engineer' => 'Engineer', 'requested' => 'Sent for Approval', 'waiting' => 'Days Waiting']],

            'department' => ['title' => $title, 'summary' => [], 'rows' => $jobs->where('status', '!=', 'draft')->groupBy(fn ($j) => ($j->department?->name ?? 'No department').'|'.$j->facility?->name)->map(function ($g, $key) {
                [$dept, $facility] = explode('|', $key);

                return ['facility' => $facility, 'department' => $dept, 'total' => $g->where('status', '!=', 'cancelled')->count(), 'completed' => $g->where('status', 'completed')->count(),
                    'in_progress' => $g->where('status', 'in_progress')->count(), 'pending' => $g->where('status', 'pending_approval')->count(), 'cancelled' => $g->where('status', 'cancelled')->count()];
            })->sortBy(['facility', 'department'])->values(),
                'columns' => ['facility' => 'Facility', 'department' => 'Department', 'total' => 'Total', 'completed' => 'Completed', 'in_progress' => 'In Progress', 'pending' => 'Pending Approval', 'cancelled' => 'Cancelled']],

            'cost' => $this->cost($jobs, $title),
            'summary' => $this->summary($jobs, $title),
        };
    }

    private function cost(Collection $jobs, string $title): array
    {
        $done = $jobs->where('status', 'completed');

        return ['title' => $title, 'summary' => ['total' => number_format((float) $done->sum(fn ($j) => (float) $j->asset?->purchase_cost), 2, '.', '')],
            'rows' => $done->map(fn ($j) => ['job' => $j->job_number, 'asset' => $j->asset?->asset_tag, 'equipment' => $j->asset?->name, 'department' => $j->department?->name,
                'completed' => $j->completed_at?->format('Y-m-d'), 'cost' => $j->asset?->purchase_cost])->values(),
            'columns' => ['job' => 'Job No', 'asset' => 'Asset ID', 'equipment' => 'Equipment', 'department' => 'Department', 'completed' => 'Completed On', 'cost' => 'Asset Cost (INR)']];
    }

    private function summary(Collection $jobs, string $title): array
    {
        $done = $jobs->where('status', 'completed')->filter(fn ($j) => $j->submitted_at && $j->completed_at);
        $avg = $done->count() ? round($done->avg(fn ($j) => $j->submitted_at->diffInHours($j->completed_at) / 24), 1) : null;

        return ['title' => $title, 'summary' => ['average_days' => $avg],
            'rows' => collect(InstallationJob::STATUSES)->map(fn ($label, $key) => ['status' => $label, 'jobs' => $jobs->where('status', $key)->count()])->values()
                ->push(['status' => 'Average days to complete', 'jobs' => $avg]),
            'columns' => ['status' => 'Status', 'jobs' => 'Jobs']];
    }
}
