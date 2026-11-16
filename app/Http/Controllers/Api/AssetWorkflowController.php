<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDisposal;
use App\Models\AssetStatusChange;
use App\Models\AssetTransfer;
use App\Services\AssetWorkflow;
use App\Services\FacilityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Transfers, status changes (lifecycle) and retirement / disposal. Rules live in AssetWorkflow. */
class AssetWorkflowController extends Controller
{
    public function __construct(private AssetWorkflow $workflow) {}

    private function asset(Request $request, int $id): Asset
    {
        $asset = Asset::findOrFail($id);
        abort_unless($request->user()->canAccessFacility($asset->facility_id), 404);

        return $asset;
    }

    private function transfer(Request $request, int $id): AssetTransfer
    {
        $t = AssetTransfer::with('asset')->findOrFail($id);
        $user = $request->user();
        abort_unless($user->canAccessFacility($t->from_facility_id) || $user->canAccessFacility($t->to_facility_id), 404);

        return $t;
    }

    /* ------------------------------------------------------------ transfers */

    public function transfers(Request $request): JsonResponse
    {
        $ids = FacilityContext::ids($request->user());
        $query = AssetTransfer::with(['asset:id,asset_tag,name', 'fromFacility:id,name', 'fromLocation:id,name', 'toFacility:id,name', 'toDepartment:id,name', 'toLocation:id,name', 'requester:id,name', 'decider:id,name'])
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_facility_id', $ids)->orWhereIn('to_facility_id', $ids)));

        if (array_key_exists($request->input('status'), AssetTransfer::STATUSES)) {
            $query->where('status', $request->input('status'));
        }
        if ($term = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('asset', fn ($a) => $a->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like));
        }
        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->integer('asset_id'));
        }

        $page = $query->latest('id')->paginate(min(max($request->integer('per_page', 10), 5), 50));
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($t) => $this->transferRow($t, $user))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    public function requestTransfer(Request $request, int $id): JsonResponse
    {
        $asset = $this->asset($request, $id);
        $data = $request->validate([
            'to_facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'to_department_id' => ['required', 'integer', 'exists:departments,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'requested_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], ['requested_date.after_or_equal' => 'The requested date cannot be in the past.']);

        $transfer = $this->workflow->requestTransfer($request->user(), $asset, $data);

        return response()->json(['success' => true, 'message' => 'Transfer submitted for approval.', 'data' => ['id' => $transfer->id, 'url' => route('assets.transfers')]], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $t = $this->workflow->decideTransfer($request->user(), $this->transfer($request, $id), true, $request->input('note'));

        return response()->json(['success' => true, 'message' => 'Transfer approved. The asset has been moved.', 'data' => $this->transferRow($t->fresh($this->relations()), $request->user())]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:255']], ['note.required' => 'Please give a reason for rejecting the transfer.']);
        $t = $this->workflow->decideTransfer($request->user(), $this->transfer($request, $id), false, $request->input('note'));

        return response()->json(['success' => true, 'message' => 'Transfer rejected.', 'data' => $this->transferRow($t->fresh($this->relations()), $request->user())]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $t = $this->workflow->cancelTransfer($request->user(), $this->transfer($request, $id));

        return response()->json(['success' => true, 'message' => 'Transfer cancelled.', 'data' => $this->transferRow($t->fresh($this->relations()), $request->user())]);
    }

    private function relations(): array
    {
        return ['asset:id,asset_tag,name', 'fromFacility:id,name', 'fromLocation:id,name', 'toFacility:id,name', 'toDepartment:id,name', 'toLocation:id,name', 'requester:id,name', 'decider:id,name'];
    }

    private function transferRow(AssetTransfer $t, $user): array
    {
        $pending = $t->status === 'pending';

        return [
            'id' => $t->id, 'asset_id' => $t->asset_id, 'asset_tag' => $t->asset?->asset_tag, 'asset_name' => $t->asset?->name, 'asset_url' => route('assets.show', $t->asset_id),
            'from' => collect([$t->fromFacility?->name, $t->fromLocation?->name])->filter()->implode(' › '),
            'to' => collect([$t->toFacility?->name, $t->toDepartment?->name, $t->toLocation?->name])->filter()->implode(' › '),
            'requested_date' => $t->requested_date->format('d M Y'), 'reason' => $t->reason, 'remarks' => $t->remarks,
            'status' => $t->status, 'status_label' => AssetTransfer::STATUSES[$t->status], 'requested_by' => $t->requester?->name,
            'decided_by' => $t->decider?->name, 'decision_note' => $t->decision_note,
            'can' => [
                'approve' => $pending && $user->can('assets.approve') && $t->requested_by !== $user->id && $user->canAccessFacility($t->from_facility_id),
                'cancel' => $pending && $t->requested_by === $user->id,
            ],
        ];
    }

    /* ------------------------------------------------------------ lifecycle */

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $asset = $this->asset($request, $id);
        $data = $request->validate([
            'status' => ['required', Rule::in(Asset::EDITABLE_STATUSES)],
            'reason' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], ['effective_date.before_or_equal' => 'The effective date cannot be in the future.']);

        $this->workflow->changeStatus($request->user(), $asset, $data['status'], $data['reason'], $data['effective_date'], $data['remarks'] ?? null);

        return response()->json(['success' => true, 'message' => 'Asset status updated.', 'data' => ['url' => route('assets.show', $asset)]]);
    }

    public function lifecycle(Request $request): JsonResponse
    {
        $ids = FacilityContext::ids($request->user());
        $query = AssetStatusChange::with(['asset:id,asset_tag,name,facility_id', 'user:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereHas('asset', fn ($a) => $a->whereIn('facility_id', $ids)));

        if (array_key_exists($request->input('status'), Asset::STATUSES)) {
            $query->where('to_status', $request->input('status'));
        }
        if ($term = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('asset', fn ($a) => $a->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like));
        }

        $page = $query->latest('effective_date')->latest('id')->paginate(min(max($request->integer('per_page', 10), 5), 50));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($c) => [
                'id' => $c->id, 'asset_tag' => $c->asset?->asset_tag, 'asset_name' => $c->asset?->name, 'asset_url' => route('assets.show', $c->asset_id),
                'from' => $c->from_status, 'from_label' => Asset::STATUSES[$c->from_status] ?? $c->from_status,
                'to' => $c->to_status, 'to_label' => Asset::STATUSES[$c->to_status] ?? $c->to_status,
                'reason' => $c->reason, 'effective_date' => $c->effective_date->format('d M Y'), 'remarks' => $c->remarks, 'by' => $c->user?->name,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    /* ------------------------------------------------------------- disposal */

    public function dispose(Request $request, int $id): JsonResponse
    {
        $asset = $this->asset($request, $id);
        $data = $request->validate([
            'type' => ['required', Rule::in(['retirement', 'disposal'])],
            'reason' => ['required', Rule::in(array_keys(AssetDisposal::REASONS))],
            'condition' => ['required', Rule::in(array_keys(AssetDisposal::CONDITIONS))],
            'disposal_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], ['disposal_date.before_or_equal' => 'The date cannot be in the future.']);

        $this->workflow->dispose($request->user(), $asset, $data);

        return response()->json(['success' => true, 'message' => $data['type'] === 'retirement' ? 'Asset retired.' : 'Asset disposed.', 'data' => ['url' => route('assets.show', $asset)]]);
    }
}
