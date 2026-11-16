<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Manufacturer;
use App\Models\PriorityLevel;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OrganizationStep2bTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Facility $fa;

    private Facility $fb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->fa = Facility::create(['name' => 'City General', 'code' => 'H001', 'is_active' => true]);
        $this->fb = Facility::create(['name' => 'Other Hospital', 'code' => 'H002', 'is_active' => true]);
    }

    private function admin()
    {
        return $this->actingAs($this->makeUser('admin'));
    }

    public function test_new_pages_render_and_are_gated(): void
    {
        $this->admin();
        foreach (['manufacturers', 'staff', 'shifts', 'on-call-roster', 'priority-levels'] as $page) {
            $this->get("/organization/$page")->assertOk()->assertSee('data-org-page', false);
        }
        $this->get('/organization/staff')->assertDontSee('data-org-add', false);

        $this->actingAs($this->makeUser('read_only'))->get('/organization/shifts')->assertForbidden();
        $this->getJson('/api/org/staff')->assertForbidden();
    }

    public function test_manufacturer_crud_and_asset_guard(): void
    {
        $this->admin();
        $id = $this->postJson('/api/org/manufacturers', ['code' => 'M001', 'name' => 'GE Healthcare', 'country' => 'USA'])->assertCreated()->json('data.id');
        $this->postJson('/api/org/manufacturers', ['code' => 'M001', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->getJson('/api/org/manufacturers?q=GE')->assertJsonPath('meta.total', 1);

        Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'A-1', 'name' => 'Monitor', 'manufacturer_id' => $id]);
        $this->deleteJson("/api/org/manufacturers/$id")->assertStatus(422);
        Asset::query()->delete();
        $this->deleteJson("/api/org/manufacturers/$id")->assertOk();
        $this->assertSame(0, Manufacturer::count());
    }

    public function test_staff_directory_is_facility_scoped_and_edit_maps_department(): void
    {
        $mine = $this->makeUser('technician', [$this->fa->id], ['name' => 'Mr. Arvind', 'employee_id' => 'EMP-1001']);
        $foreign = $this->makeUser('technician', [$this->fb->id], ['name' => 'Foreign Staff']);
        $deptA = Department::create(['facility_id' => $this->fa->id, 'code' => 'D1', 'name' => 'Biomedical Engineering', 'type' => 'non_clinical']);
        $deptB = Department::create(['facility_id' => $this->fb->id, 'code' => 'D1', 'name' => 'Radiology', 'type' => 'clinical']);

        $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
        $names = collect($this->getJson('/api/org/staff')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Mr. Arvind', $names);
        $this->assertNotContains('Foreign Staff', $names);
        $this->getJson('/api/org/staff?q=EMP-1001')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.role', 'Technician');

        $this->putJson("/api/org/staff/{$mine->id}", ['department_id' => $deptA->id, 'designation' => 'Biomedical Engineer'])
            ->assertOk()->assertJsonPath('data.department', 'Biomedical Engineering');
        $this->assertSame($deptA->id, $mine->fresh()->department_id);

        // a department of a facility the person does not belong to, and a person outside my facilities
        $this->putJson("/api/org/staff/{$mine->id}", ['department_id' => $deptB->id])->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->putJson("/api/org/staff/{$foreign->id}", ['designation' => 'X'])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.updated']);
    }

    public function test_protected_user_fields_cannot_be_changed_through_staff_update(): void
    {
        $mine = $this->makeUser('technician', [$this->fa->id], ['employee_id' => 'EMP-9', 'status' => 'active']);
        $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
        $this->putJson("/api/org/staff/{$mine->id}", ['designation' => 'Tech', 'status' => 'disabled', 'employee_id' => 'HACK', 'email' => 'x@y.z'])->assertOk();
        $fresh = $mine->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('EMP-9', $fresh->employee_id);
    }

    public function test_shift_validation_and_roster_workflow(): void
    {
        $user = $this->makeUser('technician', [$this->fa->id], ['name' => 'Tech One']);
        $outsider = $this->makeUser('technician', [$this->fb->id]);
        $this->admin();

        $base = ['facility_id' => $this->fa->id, 'name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'type' => 'regular'];
        $shiftId = $this->postJson('/api/org/shifts', $base)->assertCreated()->assertJsonPath('data.start_label', '06:00 AM')->json('data.id');
        $this->postJson('/api/org/shifts', $base)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/org/shifts', ['name' => 'Same', 'start_time' => '08:00', 'end_time' => '08:00'] + $base)->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/org/shifts', ['name' => 'Bad', 'start_time' => '25:00'] + $base)->assertStatus(422)->assertJsonValidationErrors('start_time');
        // overnight shift is allowed
        $this->postJson('/api/org/shifts', ['name' => 'Night', 'start_time' => '20:00', 'end_time' => '06:00', 'type' => 'on_call'] + $base)->assertCreated();

        $entry = ['facility_id' => $this->fa->id, 'shift_id' => $shiftId, 'user_id' => $user->id, 'shift_date' => '2026-12-01'];
        $id = $this->postJson('/api/org/shift-assignments', $entry)->assertCreated()->assertJsonPath('data.staff', 'Tech One')->json('data.id');
        $this->postJson('/api/org/shift-assignments', $entry)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/org/shift-assignments', ['user_id' => $outsider->id] + $entry)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/org/shift-assignments', ['shift_date' => '01/12/2026'] + $entry)->assertStatus(422)->assertJsonValidationErrors('shift_date');

        $this->getJson("/api/org/shift-assignments?shift_id=$shiftId&from=2026-12-01&to=2026-12-31")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/org/shift-assignments?from=2027-01-01')->assertJsonPath('meta.total', 0);

        // a shift with roster entries cannot be deleted; once the entry is removed it can
        $this->deleteJson("/api/org/shifts/$shiftId")->assertStatus(422);
        $this->deleteJson("/api/org/shift-assignments/$id")->assertOk();
        $this->deleteJson("/api/org/shifts/$shiftId")->assertOk();
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_roster_respects_facility_access(): void
    {
        $shiftB = Shift::create(['facility_id' => $this->fb->id, 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00', 'type' => 'regular']);
        $other = $this->makeUser('technician', [$this->fb->id]);
        $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
        $this->postJson('/api/org/shift-assignments', ['facility_id' => $this->fb->id, 'shift_id' => $shiftB->id, 'user_id' => $other->id, 'shift_date' => '2026-12-01'])->assertForbidden();
        $this->getJson('/api/org/shifts')->assertJsonPath('meta.total', 0);
    }

    public function test_priority_levels_are_seeded_and_validated(): void
    {
        $this->admin();
        $this->assertSame(['critical', 'high', 'medium', 'low'], PriorityLevel::orderBy('sort_order')->pluck('code')->all());
        $this->assertSame('30 Minutes', $this->getJson('/api/org/priority-levels')->json('data.0.response_label'));
        $this->assertSame('4 Hours', $this->getJson('/api/org/priority-levels')->json('data.0.resolution_label'));
        $this->assertSame('7 Days', $this->getJson('/api/org/priority-levels')->json('data.3.resolution_label'));

        $high = PriorityLevel::where('code', 'high')->first();
        $this->putJson("/api/org/priority-levels/{$high->id}", ['name' => 'High', 'color' => '#e08a1e', 'response_minutes' => 600, 'resolution_minutes' => 60])
            ->assertStatus(422)->assertJsonValidationErrors('resolution_minutes');
        $this->putJson("/api/org/priority-levels/{$high->id}", ['name' => 'High', 'color' => 'orange', 'response_minutes' => 60, 'resolution_minutes' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('color');
        // code is immutable
        $this->putJson("/api/org/priority-levels/{$high->id}", ['code' => 'changed', 'name' => 'High', 'color' => '#e08a1e', 'response_minutes' => 60, 'resolution_minutes' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->putJson("/api/org/priority-levels/{$high->id}", ['name' => 'High', 'color' => '#e08a1e', 'response_minutes' => 60, 'resolution_minutes' => 120])->assertOk();

        $this->postJson('/api/org/priority-levels', ['code' => 'urgent', 'name' => 'Urgent', 'color' => '#ff0000', 'response_minutes' => 15, 'resolution_minutes' => 60])->assertCreated();
        $this->postJson('/api/org/priority-levels', ['code' => 'Bad Code', 'name' => 'X', 'color' => '#ff0000', 'response_minutes' => 1, 'resolution_minutes' => 2])->assertStatus(422);
    }

    public function test_work_order_deadlines_come_from_priority_sla_and_priority_in_use_cannot_be_deleted(): void
    {
        $this->travelTo(now()->startOfMinute());
        $order = WorkOrder::create(['facility_id' => $this->fa->id, 'number' => 'WO-1', 'title' => 'Ventilator fault', 'type' => 'breakdown', 'priority' => 'critical']);
        $this->assertTrue($order->response_due_at->equalTo(now()->addMinutes(30)));
        $this->assertTrue($order->due_at->equalTo(now()->addMinutes(240)));

        // an explicit due date wins, and later SLA edits do not rewrite existing orders
        $explicit = WorkOrder::create(['facility_id' => $this->fa->id, 'number' => 'WO-2', 'title' => 'x', 'type' => 'preventive', 'priority' => 'low', 'due_at' => now()->addDay()]);
        $this->assertTrue($explicit->due_at->equalTo(now()->addDay()));
        $critical = PriorityLevel::where('code', 'critical')->first();
        $critical->update(['resolution_minutes' => 10]);
        $this->assertTrue($order->fresh()->due_at->equalTo(now()->addMinutes(240)));

        // unknown priority: no failure, no deadline
        $this->assertNull(WorkOrder::create(['facility_id' => $this->fa->id, 'number' => 'WO-3', 'title' => 'y', 'type' => 'preventive', 'priority' => 'whatever'])->due_at);

        $this->admin()->deleteJson("/api/org/priority-levels/{$critical->id}")->assertStatus(422);

        $this->travel(5)->hours();
        $this->assertTrue($order->fresh()->isSlaBreached());
        $this->assertFalse($explicit->fresh()->isSlaBreached());
    }
}
