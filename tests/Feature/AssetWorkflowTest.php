<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AssetWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Facility $fa;

    private Facility $fb;

    private Department $dA;

    private Department $dA2;

    private Department $dB;

    private Location $lA;

    private Location $lA2;

    private Location $lB;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->fa = Facility::create(['name' => 'City General', 'code' => 'H001', 'is_active' => true]);
        $this->fb = Facility::create(['name' => 'Other Hospital', 'code' => 'H002', 'is_active' => true]);
        $this->dA = Department::create(['facility_id' => $this->fa->id, 'code' => 'D1', 'name' => 'ICU', 'type' => 'clinical']);
        $this->dA2 = Department::create(['facility_id' => $this->fa->id, 'code' => 'D2', 'name' => 'Cardiology', 'type' => 'clinical']);
        $this->dB = Department::create(['facility_id' => $this->fb->id, 'code' => 'D1', 'name' => 'Radiology', 'type' => 'clinical']);
        $this->lA = Location::create(['facility_id' => $this->fa->id, 'code' => 'L1', 'name' => 'ICU-1', 'type' => 'icu']);
        $this->lA2 = Location::create(['facility_id' => $this->fa->id, 'code' => 'L2', 'name' => 'CARD-1', 'type' => 'room']);
        $this->lB = Location::create(['facility_id' => $this->fb->id, 'code' => 'L1', 'name' => 'RAD-1', 'type' => 'room']);
        $this->asset = Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0001', 'name' => 'Monitor', 'department_id' => $this->dA->id, 'location_id' => $this->lA->id]);
    }

    private function as(string $role, array $facilities = null): User
    {
        $u = $this->makeUser($role, $facilities ?? [$this->fa->id, $this->fb->id]);
        $this->actingAs($u);

        return $u;
    }

    private function transferPayload(array $over = []): array
    {
        return array_merge(['to_facility_id' => $this->fa->id, 'to_department_id' => $this->dA2->id, 'to_location_id' => $this->lA2->id,
            'requested_date' => now()->format('Y-m-d'), 'reason' => 'Department requirement'], $over);
    }

    /* ------------------------------------------------------------ transfer */

    public function test_pages_render_and_are_permission_gated(): void
    {
        $this->as('facility_manager');
        foreach (['/assets/transfers', '/assets/lifecycle', "/assets/{$this->asset->id}/transfer", "/assets/{$this->asset->id}/status", "/assets/{$this->asset->id}/dispose"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->as('technician', [$this->fa->id]);
        $this->get('/assets/transfers')->assertOk();
        $this->get("/assets/{$this->asset->id}/transfer")->assertForbidden();
        $this->get("/assets/{$this->asset->id}/dispose")->assertForbidden();
        $this->as('read_only', [$this->fa->id]);
        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertForbidden();
        $this->postJson("/api/assets/{$this->asset->id}/status", [])->assertForbidden();
        $this->postJson("/api/assets/{$this->asset->id}/dispose", [])->assertForbidden();
    }

    public function test_transfer_request_then_approval_moves_the_asset_atomically(): void
    {
        $requester = $this->as('biomedical_engineer', [$this->fa->id]);
        $res = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertCreated();
        $transfer = AssetTransfer::findOrFail($res->json('data.id'));
        $this->assertSame('pending', $transfer->status);
        $this->assertSame($this->lA->id, $transfer->from_location_id);

        // asset has not moved yet; a second request is refused
        $this->assertSame($this->lA->id, $this->asset->fresh()->location_id);
        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertStatus(422)->assertJsonPath('message', 'This asset already has a transfer waiting for approval.');
        $this->getJson("/api/assets/{$this->asset->id}")->assertJsonPath('data.pending_transfer.id', $transfer->id)->assertJsonPath('data.can.transfer', false);

        // the requester (no assets.approve) cannot approve
        $this->postJson("/api/assets/transfers/{$transfer->id}/approve")->assertForbidden();

        $this->as('facility_manager', [$this->fa->id]);
        $this->postJson("/api/assets/transfers/{$transfer->id}/approve")->assertOk();
        $asset = $this->asset->fresh();
        $this->assertSame($this->dA2->id, $asset->department_id);
        $this->assertSame($this->lA2->id, $asset->location_id);
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertNotNull($transfer->fresh()->decided_at);

        $log = \App\Models\AuditLog::where('action', 'asset.transferred')->first();
        $this->assertSame('ICU-1', $log->old_values['location']);
        $this->assertSame('CARD-1', $log->new_values['location']);

        // approving twice is a conflict
        $this->postJson("/api/assets/transfers/{$transfer->id}/approve")->assertStatus(409);
        $this->assertSame($requester->id, $transfer->requested_by);
    }

    public function test_cross_facility_transfer_updates_the_facility(): void
    {
        $this->as('admin');
        $id = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload(['to_facility_id' => $this->fb->id, 'to_department_id' => $this->dB->id, 'to_location_id' => $this->lB->id]))
            ->assertCreated()->json('data.id');
        $this->as('super_admin')->id; // a different approver
        $this->postJson("/api/assets/transfers/$id/approve")->assertOk();
        $this->assertSame($this->fb->id, $this->asset->fresh()->facility_id);
    }

    public function test_admin_cannot_approve_own_request(): void
    {
        $this->as('admin');
        $id = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/assets/transfers/$id/approve")->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject your own transfer request.');
        $this->assertSame('pending', AssetTransfer::find($id)->status);
    }

    public function test_reject_needs_a_reason_and_leaves_the_asset_alone(): void
    {
        $this->as('biomedical_engineer', [$this->fa->id]);
        $id = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->json('data.id');
        $this->as('facility_manager', [$this->fa->id]);
        $this->postJson("/api/assets/transfers/$id/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/assets/transfers/$id/reject", ['note' => 'Not needed'])->assertOk();
        $t = AssetTransfer::find($id);
        $this->assertSame('rejected', $t->status);
        $this->assertSame('Not needed', $t->decision_note);
        $this->assertSame($this->lA->id, $this->asset->fresh()->location_id);
        // after rejection a new request is allowed again
        $this->as('biomedical_engineer', [$this->fa->id]);
        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertCreated();
    }

    public function test_only_the_requester_can_cancel(): void
    {
        $this->as('biomedical_engineer', [$this->fa->id]);
        $id = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->json('data.id');
        $this->as('facility_manager', [$this->fa->id]);
        $this->postJson("/api/assets/transfers/$id/cancel")->assertForbidden();
        $this->actingAs(AssetTransfer::find($id)->requester);
        $this->postJson("/api/assets/transfers/$id/cancel")->assertOk();
        $this->assertSame('cancelled', AssetTransfer::find($id)->status);
        $this->postJson("/api/assets/transfers/$id/cancel")->assertStatus(409);
    }

    public function test_transfer_validation_and_facility_rules(): void
    {
        $this->as('facility_manager', [$this->fa->id]);
        $url = "/api/assets/{$this->asset->id}/transfers";
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['to_facility_id', 'to_department_id', 'to_location_id', 'requested_date', 'reason']);
        $this->postJson($url, $this->transferPayload(['requested_date' => '2020-01-01']))->assertStatus(422)->assertJsonValidationErrors('requested_date');
        // destination facility the requester cannot access
        $this->postJson($url, $this->transferPayload(['to_facility_id' => $this->fb->id, 'to_department_id' => $this->dB->id, 'to_location_id' => $this->lB->id]))->assertForbidden();
        // department of another facility
        $this->postJson($url, $this->transferPayload(['to_department_id' => $this->dB->id]))->assertStatus(422);
        // same place as now
        $this->postJson($url, $this->transferPayload(['to_department_id' => $this->dA->id, 'to_location_id' => $this->lA->id]))->assertStatus(422);
        // inactive destination location
        $this->lA2->update(['is_active' => false]);
        $this->postJson($url, $this->transferPayload())->assertStatus(422);
    }

    public function test_approval_detects_an_asset_that_moved_in_the_meantime(): void
    {
        $this->as('biomedical_engineer', [$this->fa->id]);
        $id = $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->json('data.id');
        $this->asset->update(['location_id' => $this->lA2->id]);   // moved by someone else
        $this->as('facility_manager', [$this->fa->id]);
        $this->postJson("/api/assets/transfers/$id/approve")->assertStatus(409);
        $this->assertSame('pending', AssetTransfer::find($id)->status);
    }

    public function test_transfer_list_is_scoped_and_filterable(): void
    {
        $this->as('biomedical_engineer', [$this->fa->id]);
        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertCreated();
        $this->getJson('/api/assets/transfers')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status_label', 'Pending Approval')->assertJsonPath('data.0.can.cancel', true);
        $this->getJson('/api/assets/transfers?status=approved')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/assets/transfers?q=BIO-0001')->assertJsonPath('meta.total', 1);

        $this->as('facility_manager', [$this->fb->id]);   // unrelated facility sees nothing and cannot act
        $this->getJson('/api/assets/transfers')->assertJsonPath('meta.total', 0);
        $id = AssetTransfer::first()->id;
        $this->postJson("/api/assets/transfers/$id/approve")->assertNotFound();
    }

    /* --------------------------------------------------------------- status */

    public function test_status_change_records_lifecycle_audit_and_validates(): void
    {
        $this->as('biomedical_engineer', [$this->fa->id]);
        $url = "/api/assets/{$this->asset->id}/status";
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['status', 'reason', 'effective_date']);
        $this->postJson($url, ['status' => 'operational', 'reason' => 'x', 'effective_date' => now()->format('Y-m-d')])->assertStatus(422); // already operational
        $this->postJson($url, ['status' => 'retired', 'reason' => 'x', 'effective_date' => now()->format('Y-m-d')])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson($url, ['status' => 'under_maintenance', 'reason' => 'Breakdown', 'effective_date' => now()->addDay()->format('Y-m-d')])->assertStatus(422)->assertJsonValidationErrors('effective_date');

        $this->postJson($url, ['status' => 'under_maintenance', 'reason' => 'Breakdown', 'effective_date' => now()->format('Y-m-d'), 'remarks' => 'Display flickering'])->assertOk();
        $this->assertSame('under_maintenance', $this->asset->fresh()->status);
        $this->assertDatabaseHas('asset_status_changes', ['asset_id' => $this->asset->id, 'from_status' => 'operational', 'to_status' => 'under_maintenance', 'reason' => 'Breakdown']);

        $history = $this->getJson("/api/assets/{$this->asset->id}/history")->json('data');
        $this->assertSame('asset.status_changed', $history[0]['action']);
        $this->getJson('/api/assets/lifecycle')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.to_label', 'Under Maintenance');
        $this->getJson('/api/assets/lifecycle?status=operational')->assertJsonPath('meta.total', 0);

        // other facility cannot
        $this->as('biomedical_engineer', [$this->fb->id]);
        $this->postJson($url, ['status' => 'operational', 'reason' => 'x', 'effective_date' => now()->format('Y-m-d')])->assertNotFound();
    }

    /* ------------------------------------------------------------- disposal */

    public function test_retire_then_dispose_closes_the_asset(): void
    {
        $this->as('facility_manager', [$this->fa->id]);
        $url = "/api/assets/{$this->asset->id}/dispose";
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['type', 'reason', 'condition', 'disposal_date']);
        $body = ['type' => 'retirement', 'reason' => 'end_of_useful_life', 'condition' => 'not_working', 'disposal_date' => now()->format('Y-m-d'), 'remarks' => 'Beyond repair'];

        $this->postJson($url, $body)->assertOk()->assertJsonPath('message', 'Asset retired.');
        $this->assertSame('retired', $this->asset->fresh()->status);
        $this->assertDatabaseHas('asset_disposals', ['asset_id' => $this->asset->id, 'type' => 'retirement', 'condition' => 'not_working']);
        $this->assertDatabaseHas('asset_status_changes', ['asset_id' => $this->asset->id, 'to_status' => 'retired']);

        // retired assets are read-only and leave the dashboard counts
        $this->putJson("/api/assets/{$this->asset->id}", ['name' => 'x'])->assertStatus(422);
        $this->postJson("/api/assets/{$this->asset->id}/status", ['status' => 'operational', 'reason' => 'x', 'effective_date' => now()->format('Y-m-d')])->assertStatus(422);
        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertStatus(422);
        $this->getJson('/api/assets/dashboard')->assertJsonPath('data.total.value', 0);
        $this->postJson($url, $body)->assertStatus(422);   // cannot retire twice

        $this->postJson($url, ['type' => 'disposal'] + $body)->assertOk()->assertJsonPath('message', 'Asset disposed.');
        $this->assertSame('disposed', $this->asset->fresh()->status);
        $this->postJson($url, ['type' => 'disposal'] + $body)->assertStatus(422);
        $this->get("/assets/{$this->asset->id}/dispose")->assertForbidden();
    }

    public function test_disposal_is_blocked_by_open_work_orders_and_pending_transfers_and_needs_approve_permission(): void
    {
        $body = ['type' => 'retirement', 'reason' => 'damaged', 'condition' => 'not_working', 'disposal_date' => now()->format('Y-m-d')];
        $this->as('biomedical_engineer', [$this->fa->id]);
        $this->postJson("/api/assets/{$this->asset->id}/dispose", $body)->assertForbidden();

        $this->as('facility_manager', [$this->fa->id]);
        $wo = WorkOrder::create(['facility_id' => $this->fa->id, 'asset_id' => $this->asset->id, 'number' => 'WO-1', 'title' => 'x', 'type' => 'breakdown', 'priority' => 'high']);
        $this->postJson("/api/assets/{$this->asset->id}/dispose", $body)->assertStatus(422);
        $wo->update(['status' => 'completed']);

        $this->postJson("/api/assets/{$this->asset->id}/transfers", $this->transferPayload())->assertCreated();
        $this->postJson("/api/assets/{$this->asset->id}/dispose", $body)->assertStatus(422);
        AssetTransfer::query()->update(['status' => 'cancelled']);

        $this->postJson("/api/assets/{$this->asset->id}/dispose", $body + [])->assertOk();
        $this->postJson("/api/assets/{$this->asset->id}/dispose", ['disposal_date' => now()->addDay()->format('Y-m-d')] + $body)->assertStatus(422);
    }

    public function test_disposal_date_cannot_precede_purchase(): void
    {
        $this->asset->update(['purchase_date' => '2024-06-01']);
        $this->as('facility_manager', [$this->fa->id]);
        $this->postJson("/api/assets/{$this->asset->id}/dispose", ['type' => 'retirement', 'reason' => 'damaged', 'condition' => 'working', 'disposal_date' => '2024-01-01'])
            ->assertStatus(422)->assertJsonPath('message', 'The date cannot be before the purchase date.');
    }
}
