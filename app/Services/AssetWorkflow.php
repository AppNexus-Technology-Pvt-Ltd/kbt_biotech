<?php

namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\Asset;
use App\Models\AssetDisposal;
use App\Models\AssetStatusChange;
use App\Models\AssetTransfer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for asset transfers, status changes and retirement / disposal.
 * Every method is one transaction and writes the audit trail. Callers have already checked the
 * route permission (assets.edit / assets.approve) and that the user may see the asset's facility.
 */
class AssetWorkflow
{
    /* ------------------------------------------------------------ transfer */

    /** @param array{to_facility_id:int,to_department_id:int,to_location_id:int,requested_date:string,reason:string,remarks?:?string} $data */
    public function requestTransfer(User $user, Asset $asset, array $data): AssetTransfer
    {
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            $this->assertLive($asset, 'transferred');

            if (AssetTransfer::where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
                throw new WorkflowException('This asset already has a transfer waiting for approval.');
            }
            if (! $user->canAccessFacility((int) $data['to_facility_id'])) {
                throw new WorkflowException('You can only transfer assets to facilities you have access to.', 403);
            }
            $this->assertDestination($data);
            if ((int) $data['to_facility_id'] === $asset->facility_id && (int) $data['to_location_id'] === $asset->location_id && (int) $data['to_department_id'] === $asset->department_id) {
                throw new WorkflowException('The asset is already in that facility, department and location.');
            }

            $transfer = AssetTransfer::create([
                'asset_id' => $asset->id, 'from_facility_id' => $asset->facility_id, 'from_department_id' => $asset->department_id, 'from_location_id' => $asset->location_id,
                'to_facility_id' => $data['to_facility_id'], 'to_department_id' => $data['to_department_id'], 'to_location_id' => $data['to_location_id'],
                'requested_date' => $data['requested_date'], 'reason' => $data['reason'], 'remarks' => $data['remarks'] ?? null,
                'status' => 'pending', 'requested_by' => $user->id,
            ]);
            AuditLogger::log('asset.transfer_requested', $user, $asset, null, ['to' => $this->placeOf($transfer, 'to'), 'reason' => $data['reason']]);

            return $transfer;
        });
    }

    public function decideTransfer(User $approver, AssetTransfer $transfer, bool $approve, ?string $note): AssetTransfer
    {
        return DB::transaction(function () use ($approver, $transfer, $approve, $note) {
            $transfer = AssetTransfer::lockForUpdate()->findOrFail($transfer->id);
            $asset = Asset::lockForUpdate()->findOrFail($transfer->asset_id);

            if ($transfer->status !== 'pending') {
                throw new WorkflowException('This transfer has already been '.$transfer->status.'.', 409);
            }
            if ($transfer->requested_by === $approver->id) {
                throw new WorkflowException('You cannot approve or reject your own transfer request.', 403);
            }
            if (! $approver->canAccessFacility($transfer->from_facility_id)) {
                throw new WorkflowException('You do not have access to the source facility of this transfer.', 403);
            }

            if (! $approve) {
                if (! $note) {
                    throw new WorkflowException('Please give a reason for rejecting the transfer.');
                }
                $transfer->update(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note]);
                AuditLogger::log('asset.transfer_rejected', $approver, $asset, null, ['reason' => $note]);

                return $transfer;
            }

            // The asset may have changed since the request: re-check before moving it.
            $this->assertLive($asset, 'transferred');
            if ($asset->facility_id !== $transfer->from_facility_id || $asset->location_id !== $transfer->from_location_id) {
                throw new WorkflowException('The asset has moved since this request was made. Reject it and raise a new transfer.', 409);
            }
            $this->assertDestination(['to_facility_id' => $transfer->to_facility_id, 'to_department_id' => $transfer->to_department_id, 'to_location_id' => $transfer->to_location_id]);

            $old = $this->placeOf($transfer, 'from');
            $asset->update(['facility_id' => $transfer->to_facility_id, 'department_id' => $transfer->to_department_id, 'location_id' => $transfer->to_location_id]);
            $transfer->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note]);
            AuditLogger::log('asset.transferred', $approver, $asset, $old, $this->placeOf($transfer->fresh(), 'to'));

            return $transfer;
        });
    }

    public function cancelTransfer(User $user, AssetTransfer $transfer): AssetTransfer
    {
        return DB::transaction(function () use ($user, $transfer) {
            $transfer = AssetTransfer::lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'pending') {
                throw new WorkflowException('Only a pending transfer can be cancelled.', 409);
            }
            if ($transfer->requested_by !== $user->id) {
                throw new WorkflowException('Only the person who requested the transfer can cancel it.', 403);
            }
            $transfer->update(['status' => 'cancelled', 'decided_by' => $user->id, 'decided_at' => now()]);
            AuditLogger::log('asset.transfer_cancelled', $user, $transfer->asset);

            return $transfer;
        });
    }

    /* ------------------------------------------------------- status change */

    public function changeStatus(User $user, Asset $asset, string $to, string $reason, string $effectiveDate, ?string $remarks): AssetStatusChange
    {
        return DB::transaction(function () use ($user, $asset, $to, $reason, $effectiveDate, $remarks) {
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            $this->assertLive($asset, 'changed');
            if (! in_array($to, Asset::EDITABLE_STATUSES, true)) {
                throw new WorkflowException('Use the retirement / disposal form to retire an asset.');
            }
            if ($to === $asset->status) {
                throw new WorkflowException('The asset is already '.strtolower(Asset::STATUSES[$to]).'.');
            }

            $change = AssetStatusChange::create([
                'asset_id' => $asset->id, 'from_status' => $asset->status, 'to_status' => $to, 'reason' => $reason,
                'effective_date' => $effectiveDate, 'remarks' => $remarks, 'changed_by' => $user->id,
            ]);
            $from = $asset->status;
            $asset->update(['status' => $to]);
            AuditLogger::log('asset.status_changed', $user, $asset, ['status' => $from], ['status' => $to, 'reason' => $reason, 'effective_date' => $effectiveDate]);

            return $change;
        });
    }

    /* --------------------------------------------------- retire / dispose */

    /** @param array{type:string,reason:string,condition:string,disposal_date:string,remarks?:?string} $data */
    public function dispose(User $user, Asset $asset, array $data): AssetDisposal
    {
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            $target = $data['type'] === 'retirement' ? 'retired' : 'disposed';

            if ($asset->status === 'disposed') {
                throw new WorkflowException('This asset has already been disposed.');
            }
            if ($asset->status === 'retired' && $target === 'retired') {
                throw new WorkflowException('This asset is already retired. It can still be disposed.');
            }
            if (WorkOrder::where('asset_id', $asset->id)->whereIn('status', WorkOrder::OPEN_STATUSES)->exists()) {
                throw new WorkflowException('Close or cancel the open work orders for this asset first.');
            }
            if (AssetTransfer::where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
                throw new WorkflowException('Resolve the pending transfer for this asset first.');
            }
            if ($asset->purchase_date && $data['disposal_date'] < $asset->purchase_date->format('Y-m-d')) {
                throw new WorkflowException('The date cannot be before the purchase date.');
            }

            $disposal = AssetDisposal::create([
                'asset_id' => $asset->id, 'type' => $data['type'], 'reason' => $data['reason'], 'condition' => $data['condition'],
                'disposal_date' => $data['disposal_date'], 'remarks' => $data['remarks'] ?? null, 'performed_by' => $user->id,
            ]);
            AssetStatusChange::create([
                'asset_id' => $asset->id, 'from_status' => $asset->status, 'to_status' => $target,
                'reason' => ucfirst($data['type']).': '.(AssetDisposal::REASONS[$data['reason']] ?? $data['reason']),
                'effective_date' => $data['disposal_date'], 'remarks' => $data['remarks'] ?? null, 'changed_by' => $user->id,
            ]);
            $from = $asset->status;
            $asset->update(['status' => $target]);
            AuditLogger::log('asset.'.($target === 'retired' ? 'retired' : 'disposed'), $user, $asset, ['status' => $from],
                ['status' => $target, 'reason' => AssetDisposal::REASONS[$data['reason']] ?? $data['reason'], 'condition' => AssetDisposal::CONDITIONS[$data['condition']] ?? $data['condition'], 'date' => $data['disposal_date']]);

            return $disposal;
        });
    }

    /* ------------------------------------------------------------- helpers */

    private function assertLive(Asset $asset, string $verb): void
    {
        if ($asset->isClosed()) {
            throw new WorkflowException('A '.strtolower(Asset::STATUSES[$asset->status]).' asset cannot be '.$verb.'.');
        }
    }

    /** Destination department / location must be active and belong to the destination facility. */
    private function assertDestination(array $d): void
    {
        $facility = Facility::find($d['to_facility_id']);
        $dept = Department::find($d['to_department_id']);
        $loc = Location::find($d['to_location_id']);
        if (! $facility?->is_active) {
            throw new WorkflowException('The destination facility is not active.');
        }
        if (! $dept || ! $dept->is_active || $dept->facility_id !== $facility->id) {
            throw new WorkflowException('The destination department is not an active department of that facility.');
        }
        if (! $loc || ! $loc->is_active || $loc->facility_id !== $facility->id) {
            throw new WorkflowException('The destination location is not an active location of that facility.');
        }
    }

    /** @return array{facility:?string,department:?string,location:?string} */
    private function placeOf(AssetTransfer $t, string $side): array
    {
        $t->loadMissing($side.'Facility', $side.'Department', $side.'Location');

        return ['facility' => $t->{$side.'Facility'}?->name, 'department' => $t->{$side.'Department'}?->name, 'location' => $t->{$side.'Location'}?->name];
    }
}
