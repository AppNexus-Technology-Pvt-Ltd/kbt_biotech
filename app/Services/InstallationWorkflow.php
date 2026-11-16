<?php

namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\Asset;
use App\Models\AssetStatusChange;
use App\Models\InstallationChecklistItem as Item;
use App\Models\InstallationHandover;
use App\Models\InstallationJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rules for installation jobs:
 *   draft -> in_progress (checklists created) -> pending_approval -> completed   (cancel from any open state)
 * A different user with installations.approve completes the job, which commissions the asset.
 * Callers have already checked the route permission and facility access.
 */
class InstallationWorkflow
{
    /* ----------------------------------------------------------------- create */

    /** @param array<string,mixed> $data validated job fields incl. asset_id */
    public function create(User $user, Asset $asset, array $data, bool $submit): InstallationJob
    {
        return DB::transaction(function () use ($user, $asset, $data, $submit) {
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            $this->assertAssetAvailable($asset);
            $this->assertEngineer($data['engineer_id'] ?? null, $asset->facility_id);

            $job = InstallationJob::create($this->fields($data) + [
                'job_number' => $this->nextNumber(), 'facility_id' => $asset->facility_id, 'asset_id' => $asset->id,
                'department_id' => $data['department_id'] ?? $asset->department_id, 'status' => 'draft', 'created_by' => $user->id,
            ]);
            AuditLogger::log('installation.created', $user, $job, null, ['job' => $job->job_number, 'asset' => $asset->asset_tag]);
            if ($submit) {
                $this->doSubmit($user, $job);
            }

            return $job;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $user, InstallationJob $job, array $data): InstallationJob
    {
        return DB::transaction(function () use ($user, $job, $data) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if (! in_array($job->status, ['draft', 'in_progress'], true)) {
                throw new WorkflowException('A '.strtolower(InstallationJob::STATUSES[$job->status]).' job can no longer be edited.');
            }
            $this->assertEngineer($data['engineer_id'] ?? null, $job->facility_id);

            if (isset($data['asset_id']) && (int) $data['asset_id'] !== $job->asset_id) {
                if ($job->status !== 'draft') {
                    throw new WorkflowException('The asset cannot be changed once the job has been submitted.');
                }
                $asset = Asset::lockForUpdate()->findOrFail($data['asset_id']);
                if ($asset->facility_id !== $job->facility_id && ! $user->canAccessFacility($asset->facility_id)) {
                    throw new WorkflowException('You do not have access to that asset.', 403);
                }
                $this->assertAssetAvailable($asset, $job->id);
                $job->asset_id = $asset->id;
                $job->facility_id = $asset->facility_id;
            }
            $old = $job->only(['type', 'priority', 'planned_date', 'engineer_id', 'department_id']);
            $job->fill($this->fields($data));
            if (array_key_exists('department_id', $data)) {
                $job->department_id = $data['department_id'];
            }
            $job->save();
            AuditLogger::log('installation.updated', $user, $job, $old, $job->only(array_keys($old)));

            return $job;
        });
    }

    public function delete(User $user, InstallationJob $job): void
    {
        DB::transaction(function () use ($user, $job) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if ($job->status !== 'draft') {
                throw new WorkflowException('Only a draft can be deleted. Cancel the job instead.');
            }
            AuditLogger::log('installation.deleted', $user, $job, ['job' => $job->job_number]);
            $job->delete();
        });
    }

    /* ------------------------------------------------------------- transitions */

    public function submit(User $user, InstallationJob $job): InstallationJob
    {
        return DB::transaction(function () use ($user, $job) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if ($job->status !== 'draft') {
                throw new WorkflowException('Only a draft can be submitted.', 409);
            }

            return $this->doSubmit($user, $job);
        });
    }

    private function doSubmit(User $user, InstallationJob $job): InstallationJob
    {
        if (! $job->engineer_id) {
            throw new WorkflowException('Assign a responsible engineer before submitting.');
        }
        $this->assertAssetAvailable(Asset::findOrFail($job->asset_id), $job->id);

        foreach (Item::TEMPLATES as $section => $rows) {
            foreach ($rows as $i => [$title, $detail]) {
                Item::create(['installation_job_id' => $job->id, 'section' => $section, 'position' => $i + 1, 'title' => $title, 'detail' => $detail, 'status' => 'pending']);
            }
        }
        $job->update(['status' => 'in_progress', 'submitted_at' => now()]);
        AuditLogger::log('installation.submitted', $user, $job);

        return $job;
    }

    public function requestApproval(User $user, InstallationJob $job): InstallationJob
    {
        return DB::transaction(function () use ($user, $job) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if ($job->status !== 'in_progress') {
                throw new WorkflowException('Only a job in progress can be sent for approval.', 409);
            }
            $problems = [];
            foreach ($this->sectionSummary($job) as $section => $s) {
                if ($s['total'] === 0) {
                    $problems[] = Item::SECTIONS[$section].' has no items';
                } elseif ($s['done'] < $s['total']) {
                    $problems[] = Item::SECTIONS[$section].': '.($s['total'] - $s['done']).' of '.$s['total'].' item(s) not complete'.($s['failed'] ? " ({$s['failed']} failed)" : '');
                }
            }
            if ($problems) {
                throw new WorkflowException('Complete all checklists first. '.implode('; ', $problems).'.');
            }
            $job->update(['status' => 'pending_approval', 'approval_requested_by' => $user->id, 'approval_requested_at' => now()]);
            AuditLogger::log('installation.approval_requested', $user, $job);

            return $job;
        });
    }

    public function approve(User $approver, InstallationJob $job): InstallationJob
    {
        return DB::transaction(function () use ($approver, $job) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            $this->assertCanDecide($approver, $job);
            $asset = Asset::lockForUpdate()->findOrFail($job->asset_id);
            if ($asset->isClosed()) {
                throw new WorkflowException('The asset is '.strtolower(Asset::STATUSES[$asset->status]).' and cannot be commissioned.');
            }

            $job->update([
                'status' => 'completed', 'completed_at' => now(), 'decided_by' => $approver->id, 'decided_at' => now(),
                'decision_note' => null, 'certificate_number' => 'CC-'.$job->job_number,
            ]);

            // Commissioning effect on the asset: installed date and (if needed) operational status.
            $old = ['installed_at' => $asset->installed_at?->format('Y-m-d'), 'status' => $asset->status];
            $updates = [];
            if ($job->type === 'new_installation' || ! $asset->installed_at) {
                $updates['installed_at'] = now()->toDateString();
            }
            if ($asset->status !== 'operational') {
                $updates['status'] = 'operational';
                AssetStatusChange::create(['asset_id' => $asset->id, 'from_status' => $asset->status, 'to_status' => 'operational',
                    'reason' => 'Commissioned: '.$job->job_number, 'effective_date' => now()->toDateString(), 'changed_by' => $approver->id]);
            }
            if ($updates) {
                $asset->update($updates);
            }
            AuditLogger::log('installation.approved', $approver, $job, null, ['certificate' => $job->certificate_number]);
            AuditLogger::log('asset.commissioned', $approver, $asset, $old, $updates + ['job' => $job->job_number]);

            return $job;
        });
    }

    public function reject(User $approver, InstallationJob $job, string $note): InstallationJob
    {
        return DB::transaction(function () use ($approver, $job, $note) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            $this->assertCanDecide($approver, $job);
            $job->update(['status' => 'in_progress', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note,
                'approval_requested_by' => null, 'approval_requested_at' => null]);
            AuditLogger::log('installation.rejected', $approver, $job, null, ['reason' => $note]);

            return $job;
        });
    }

    public function cancel(User $user, InstallationJob $job, string $reason): InstallationJob
    {
        return DB::transaction(function () use ($user, $job, $reason) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if (! $job->isOpen()) {
                throw new WorkflowException('A '.strtolower(InstallationJob::STATUSES[$job->status]).' job cannot be cancelled.', 409);
            }
            $job->update(['status' => 'cancelled', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            AuditLogger::log('installation.cancelled', $user, $job, null, ['reason' => $reason]);

            return $job;
        });
    }

    private function assertCanDecide(User $approver, InstallationJob $job): void
    {
        if ($job->status !== 'pending_approval') {
            throw new WorkflowException('This job is not waiting for approval.', 409);
        }
        if ($job->approval_requested_by === $approver->id) {
            throw new WorkflowException('You cannot approve or reject a job you sent for approval.', 403);
        }
        if (! $approver->canAccessFacility($job->facility_id)) {
            throw new WorkflowException('You do not have access to this facility.', 403);
        }
    }

    /* ---------------------------------------------------------------- handover */

    /** @param array{handover_date:string,handover_to:int,staff_trained:?array,notes:?string,signature:string} $data */
    public function handover(User $user, InstallationJob $job, array $data): InstallationHandover
    {
        $png = $this->decodeSignature($data['signature']);

        return DB::transaction(function () use ($user, $job, $data, $png) {
            $job = InstallationJob::lockForUpdate()->findOrFail($job->id);
            if ($job->status !== 'completed') {
                throw new WorkflowException('Handover is recorded after the installation has been approved and completed.');
            }
            if ($job->handover()->exists()) {
                throw new WorkflowException('This installation has already been handed over.', 409);
            }
            $recipient = User::find($data['handover_to']);
            if (! $recipient || $recipient->status !== User::STATUS_ACTIVE || ! $recipient->canAccessFacility($job->facility_id)) {
                throw new WorkflowException('The person receiving the handover must be active staff of this facility.');
            }
            if ($data['handover_date'] < $job->completed_at->format('Y-m-d')) {
                throw new WorkflowException('The handover date cannot be before the commissioning date.');
            }

            $path = 'installation-signatures/'.$job->id.'-'.Str::uuid().'.png';
            Storage::disk('local')->put($path, $png);
            $handover = InstallationHandover::create([
                'installation_job_id' => $job->id, 'handover_date' => $data['handover_date'], 'handover_to' => $recipient->id,
                'staff_trained' => $data['staff_trained'] ?: null, 'notes' => $data['notes'] ?? null, 'signature_path' => $path, 'recorded_by' => $user->id,
            ]);
            AuditLogger::log('installation.handed_over', $user, $job, null, ['to' => $recipient->name]);

            return $handover;
        });
    }

    private function decodeSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new WorkflowException('The signature is not a valid image. Please sign again.');
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            throw new WorkflowException('The signature is not a valid image. Please sign again.');
        }
        if (strlen($png) > 300 * 1024) {
            throw new WorkflowException('The signature image is too large.');
        }
        if (strlen($png) < 400) {
            throw new WorkflowException('Please sign in the box before saving.');
        }

        return $png;
    }

    /* --------------------------------------------------------------- summaries */

    /** @return array<string,array{total:int,done:int,failed:int,started:int,state:string,percent:int,completed_on:?string}> */
    public function sectionSummary(InstallationJob $job): array
    {
        $items = $job->items()->get()->groupBy('section');
        $out = [];
        foreach (array_keys(Item::SECTIONS) as $section) {
            $rows = $items[$section] ?? collect();
            $total = $rows->count();
            $done = $rows->whereIn('status', Item::DONE[$section])->count();
            $failed = $rows->where('status', 'fail')->count();
            $started = $rows->where('status', '!=', 'pending')->count();
            $state = $total && $done === $total ? 'completed' : ($failed ? 'failed' : ($started ? 'in_progress' : 'pending'));
            $out[$section] = ['total' => $total, 'done' => $done, 'failed' => $failed, 'started' => $started, 'state' => $state,
                'percent' => $total ? (int) round($done / $total * 100) : 0,
                'completed_on' => $state === 'completed' ? $rows->max('updated_at')?->format('d-m-Y') : null];
        }

        return $out;
    }

    /* ----------------------------------------------------------------- helpers */

    /** @return array<string,mixed> fillable job fields only */
    private function fields(array $d): array
    {
        return array_intersect_key($d, array_flip(['type', 'priority', 'vendor_name', 'planned_date', 'estimated_hours', 'engineer_id', 'remarks']));
    }

    private function assertAssetAvailable(Asset $asset, ?int $ignoreJob = null): void
    {
        if ($asset->isClosed()) {
            throw new WorkflowException('A '.strtolower(Asset::STATUSES[$asset->status]).' asset cannot be installed.');
        }
        $open = InstallationJob::where('asset_id', $asset->id)->whereIn('status', InstallationJob::OPEN_STATUSES)
            ->when($ignoreJob, fn ($q) => $q->whereKeyNot($ignoreJob))->first();
        if ($open) {
            throw new WorkflowException("{$asset->asset_tag} already has an open installation job ({$open->job_number}).");
        }
    }

    private function assertEngineer(?int $id, int $facilityId): void
    {
        if (! $id) {
            return;
        }
        $u = User::find($id);
        if (! $u || $u->status !== User::STATUS_ACTIVE || ! $u->canAccessFacility($facilityId) || ! $u->hasPermission('installations.manage')) {
            throw new WorkflowException('The responsible engineer must be active staff of this facility who can work on installations.');
        }
    }

    private function nextNumber(): string
    {
        $max = InstallationJob::lockForUpdate()->pluck('job_number')
            ->map(fn ($n) => preg_match('/^INST-(\d+)$/', $n, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return sprintf('INST-%04d', $max + 1);
    }
}
