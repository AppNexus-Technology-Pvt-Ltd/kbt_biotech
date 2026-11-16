<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Facility;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OrganizationTest extends TestCase
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

    private function manager()
    {
        return $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
    }

    /* ---------------------------------------------------------- pages */

    public function test_every_organization_page_renders_for_admin(): void
    {
        $this->admin();
        foreach (['facilities', 'buildings', 'departments', 'locations', 'equipment-categories'] as $page) {
            $this->get("/organization/$page")->assertOk()->assertSee('data-org-page', false);
        }
        $this->get('/organization/facilities/create')->assertOk()->assertSee('Add Facility');
        $this->get("/organization/facilities/{$this->fa->id}/edit")->assertOk()->assertSee('Edit Facility');
        $this->get('/organization')->assertRedirect(route('org.index', 'facilities'));
    }

    public function test_read_only_user_cannot_open_organization(): void
    {
        $this->actingAs($this->makeUser('read_only', [$this->fa->id]))->get('/organization/facilities')->assertForbidden();
        $this->getJson('/api/org/facilities')->assertForbidden();
    }

    public function test_auditor_can_view_but_not_change(): void
    {
        $this->actingAs($this->makeUser('auditor'));
        $this->get('/organization/buildings')->assertOk();
        $this->getJson('/api/org/buildings')->assertOk();
        $this->postJson('/api/org/buildings', ['facility_id' => $this->fa->id, 'code' => 'B1', 'name' => 'X', 'floors' => 2])->assertForbidden();
        $this->get('/organization/facilities/create')->assertForbidden();
    }

    /* ------------------------------------------------------ facilities */

    public function test_admin_creates_updates_and_deactivates_a_facility_with_image(): void
    {
        Storage::fake('local');
        $this->admin();
        $payload = ['code' => 'H005', 'name' => 'New Hospital', 'type' => 'cancer_center', 'address' => '123 Health Ave', 'city' => 'Chennai',
            'state' => 'Tamil Nadu', 'country' => 'India', 'pin_code' => '600018', 'is_active' => 1,
            'image' => UploadedFile::fake()->createWithContent('f.png', $this->pngBytes())];

        $id = $this->post('/api/org/facilities', $payload, ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $facility = Facility::findOrFail($id);
        $this->assertNotNull($facility->image_path);
        Storage::disk('local')->assertExists($facility->image_path);
        $this->get("/organization/facilities/$id/image")->assertOk();

        $payload['name'] = 'Renamed Hospital';
        unset($payload['image']);
        $this->putJson("/api/org/facilities/$id", $payload)->assertOk()->assertJsonPath('data.name', 'Renamed Hospital');

        $this->patchJson("/api/org/facilities/$id/status", ['is_active' => false])->assertOk();
        $this->assertFalse($facility->fresh()->is_active);
        $this->deleteJson("/api/org/facilities/$id")->assertStatus(405);

        $this->assertTrue(AuditLog::whereIn('action', ['facility.created', 'facility.updated', 'facility.deactivated'])->count() === 3);
    }

    public function test_facility_validation_and_unique_code(): void
    {
        $this->admin();
        $this->postJson('/api/org/facilities', [])->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'type', 'address', 'city', 'state', 'country', 'pin_code']);
        $this->postJson('/api/org/facilities', ['code' => 'H001', 'name' => 'Dup', 'type' => 'clinic', 'address' => 'a', 'city' => 'c', 'state' => 's', 'country' => 'India', 'pin_code' => '600018'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/org/facilities', ['code' => 'H9', 'name' => 'Bad', 'type' => 'nonsense', 'address' => 'a', 'city' => 'c', 'state' => 's', 'country' => 'India', 'pin_code' => '600018'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_facility_manager_cannot_create_a_facility_or_touch_another_facility(): void
    {
        $this->manager();
        $this->postJson('/api/org/facilities', ['code' => 'H7', 'name' => 'X', 'type' => 'clinic', 'address' => 'a', 'city' => 'c', 'state' => 's', 'country' => 'India', 'pin_code' => '600018'])->assertForbidden();
        $this->get('/organization/facilities/create')->assertForbidden();
        $this->getJson("/api/org/facilities/{$this->fb->id}")->assertForbidden();
        $this->get("/organization/facilities/{$this->fb->id}/edit")->assertForbidden();

        $list = $this->getJson('/api/org/facilities')->assertOk()->json('data');
        $this->assertSame(['H001'], array_column($list, 'code'));
    }

    /* ------------------------------------------------------- buildings */

    public function test_building_crud_search_and_delete_guard(): void
    {
        $this->admin();
        $id = $this->postJson('/api/org/buildings', ['facility_id' => $this->fa->id, 'code' => 'B01', 'name' => 'Main Block', 'floors' => 8, 'description' => 'Main hospital building'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/org/buildings', ['facility_id' => $this->fa->id, 'code' => 'B01', 'name' => 'Dup', 'floors' => 1])->assertStatus(422)->assertJsonValidationErrors('code');
        // same code in another facility is fine
        $this->postJson('/api/org/buildings', ['facility_id' => $this->fb->id, 'code' => 'B01', 'name' => 'Other', 'floors' => 1])->assertCreated();
        $this->postJson('/api/org/buildings', ['facility_id' => $this->fa->id, 'code' => 'B9', 'name' => 'Zero', 'floors' => 0])->assertStatus(422)->assertJsonValidationErrors('floors');

        $this->getJson('/api/org/buildings?q=Main&facility_id='.$this->fa->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson("/api/org/buildings/$id", ['code' => 'B01', 'name' => 'Main Block 2', 'floors' => 9])->assertOk()->assertJsonPath('data.floors', 9);

        Location::create(['facility_id' => $this->fa->id, 'building_id' => $id, 'code' => 'L1', 'name' => 'ICU-1', 'type' => 'icu']);
        $this->deleteJson("/api/org/buildings/$id")->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseHas('buildings', ['id' => $id]);

        Location::query()->delete();
        $this->deleteJson("/api/org/buildings/$id")->assertOk();
        $this->assertDatabaseMissing('buildings', ['id' => $id]);
    }

    public function test_manager_is_limited_to_own_facility_for_buildings(): void
    {
        $other = Building::create(['facility_id' => $this->fb->id, 'code' => 'X1', 'name' => 'Foreign', 'floors' => 2]);
        $this->manager();
        $this->postJson('/api/org/buildings', ['facility_id' => $this->fb->id, 'code' => 'B2', 'name' => 'Nope', 'floors' => 1])->assertForbidden();
        $this->putJson("/api/org/buildings/{$other->id}", ['code' => 'X1', 'name' => 'Hacked', 'floors' => 2])->assertForbidden();
        $this->deleteJson("/api/org/buildings/{$other->id}")->assertForbidden();
        $this->getJson('/api/org/buildings')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/org/options?facility_id='.$this->fb->id)->assertForbidden();
    }

    /* ----------------------------------------------------- departments */

    public function test_department_hod_must_belong_to_facility(): void
    {
        $inFacility = $this->makeUser('technician', [$this->fa->id], ['name' => 'Dr. Ramesh']);
        $outside = $this->makeUser('technician', [$this->fb->id]);
        $this->admin();

        $base = ['facility_id' => $this->fa->id, 'code' => 'D001', 'name' => 'Cardiology', 'type' => 'clinical'];
        $this->postJson('/api/org/departments', $base + ['hod_user_id' => $outside->id])->assertStatus(422)->assertJsonValidationErrors('hod_user_id');
        $this->postJson('/api/org/departments', $base + ['hod_user_id' => $inFacility->id])->assertCreated()->assertJsonPath('data.hod', 'Dr. Ramesh');
        $this->postJson('/api/org/departments', ['facility_id' => $this->fa->id, 'code' => 'D2', 'name' => 'X', 'type' => 'other'])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->getJson('/api/org/departments?type=non_clinical')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/org/departments?type=clinical')->assertJsonPath('meta.total', 1);
    }

    /* --------------------------------------------------------- locations */

    public function test_location_enforces_building_department_consistency_and_floor_range(): void
    {
        $bA = Building::create(['facility_id' => $this->fa->id, 'code' => 'B1', 'name' => 'A', 'floors' => 3]);
        $bB = Building::create(['facility_id' => $this->fb->id, 'code' => 'B1', 'name' => 'B', 'floors' => 3]);
        $dB = Department::create(['facility_id' => $this->fb->id, 'code' => 'D1', 'name' => 'Radiology', 'type' => 'clinical']);
        $this->admin();

        $base = ['facility_id' => $this->fa->id, 'code' => 'L001', 'name' => 'ICU-1', 'type' => 'icu'];
        $this->postJson('/api/org/locations', $base + ['building_id' => $bB->id])->assertStatus(422)->assertJsonValidationErrors('building_id');
        $this->postJson('/api/org/locations', $base + ['department_id' => $dB->id])->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->postJson('/api/org/locations', $base + ['building_id' => $bA->id, 'floor' => 3])->assertStatus(422)->assertJsonValidationErrors('floor');
        $id = $this->postJson('/api/org/locations', $base + ['building_id' => $bA->id, 'floor' => 2])->assertCreated()->assertJsonPath('data.building', 'A')->json('data.id');

        $this->getJson("/api/org/locations?building_id={$bA->id}")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/org/locations?type=ward')->assertJsonPath('meta.total', 0);

        // assets linked to a location block its deletion
        Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'A-1', 'name' => 'Monitor', 'location_id' => $id]);
        $this->deleteJson("/api/org/locations/$id")->assertStatus(422);
    }

    /* -------------------------------------------------------- categories */

    public function test_equipment_category_crud_unique_code_and_asset_link_guard(): void
    {
        $this->admin();
        $id = $this->postJson('/api/org/equipment-categories', ['code' => 'CAT001', 'name' => 'Patient Monitoring', 'description' => 'Vital signs'])->assertCreated()->json('data.id');
        $this->postJson('/api/org/equipment-categories', ['code' => 'CAT001', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->putJson("/api/org/equipment-categories/$id", ['code' => 'CAT001', 'name' => 'Patient Monitors'])->assertOk();
        $this->patchJson("/api/org/equipment-categories/$id/status", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/org/equipment-categories?status=inactive')->assertJsonPath('meta.total', 1);

        Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'A-2', 'name' => 'Monitor', 'category_id' => $id]);
        $this->deleteJson("/api/org/equipment-categories/$id")->assertStatus(422);
        Asset::query()->delete();
        $this->deleteJson("/api/org/equipment-categories/$id")->assertOk();
        $this->assertSame(0, EquipmentCategory::count());
    }

    /* ----------------------------------------------------------- misc */

    public function test_unauthenticated_requests_are_rejected_and_sidebar_shows_organization(): void
    {
        $this->getJson('/api/org/facilities')->assertUnauthorized();
        $this->get('/organization/facilities')->assertRedirect();

        $this->admin()->get('/organization/buildings')->assertSee('Organization')->assertSee('Equipment Categories');
    }

    public function test_options_endpoint_scopes_to_facility(): void
    {
        Building::create(['facility_id' => $this->fa->id, 'code' => 'B1', 'name' => 'A-Block', 'floors' => 2]);
        Building::create(['facility_id' => $this->fb->id, 'code' => 'B1', 'name' => 'B-Block', 'floors' => 2]);
        $this->admin();
        $names = collect($this->getJson('/api/org/options?facility_id='.$this->fa->id)->assertOk()->json('data.buildings'))->pluck('name')->all();
        $this->assertSame(['A-Block'], $names);
    }
}
