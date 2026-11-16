<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Facility;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AssetRegistryTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Facility $fa;

    private Facility $fb;

    private Department $deptA;

    private Location $locA;

    private EquipmentCategory $cat;

    private Manufacturer $mfr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->fa = Facility::create(['name' => 'City General', 'code' => 'H001', 'is_active' => true]);
        $this->fb = Facility::create(['name' => 'Other Hospital', 'code' => 'H002', 'is_active' => true]);
        $this->deptA = Department::create(['facility_id' => $this->fa->id, 'code' => 'D1', 'name' => 'ICU', 'type' => 'clinical']);
        $building = Building::create(['facility_id' => $this->fa->id, 'code' => 'B1', 'name' => 'Main Block', 'floors' => 3]);
        $this->locA = Location::create(['facility_id' => $this->fa->id, 'building_id' => $building->id, 'department_id' => $this->deptA->id, 'code' => 'L1', 'name' => 'ICU-1', 'type' => 'icu', 'floor' => 1]);
        $this->cat = EquipmentCategory::create(['code' => 'C1', 'name' => 'Patient Monitoring']);
        $this->mfr = Manufacturer::create(['code' => 'M1', 'name' => 'Philips']);
    }

    private function manager()
    {
        return $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'facility_id' => $this->fa->id, 'asset_tag' => 'BIO-0001', 'name' => 'Patient Monitor', 'category_id' => $this->cat->id,
            'manufacturer_id' => $this->mfr->id, 'model' => 'IntelliVue MX450', 'serial_number' => 'MX450-98432', 'asset_type' => 'movable',
            'department_id' => $this->deptA->id, 'location_id' => $this->locA->id, 'status' => 'operational',
            'purchase_date' => '2023-01-10', 'purchase_cost' => '850000', 'warranty_expiry_at' => '2026-01-09', 'amc_expiry_at' => '2027-01-09',
        ], $over);
    }

    private function make(array $over = []): Asset
    {
        return Asset::create(array_merge(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-'.random_int(1000, 9999), 'name' => 'Ventilator', 'status' => 'operational'], $over));
    }

    public function test_pages_render_for_a_manager(): void
    {
        $asset = $this->make();
        $this->manager();
        foreach (['/assets', '/assets/register', '/assets/create', "/assets/{$asset->id}", "/assets/{$asset->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/assets')->assertSee('Asset Dashboard')->assertSee('Asset Register');
        $this->get('/assets/register')->assertSee('Add Asset');
    }

    public function test_permissions_gate_pages_and_actions(): void
    {
        $asset = $this->make();
        $this->actingAs($this->makeUser('read_only', [$this->fa->id]));
        $this->get('/assets/register')->assertOk()->assertDontSee('Add Asset');
        $this->get('/assets/create')->assertForbidden();
        $this->get("/assets/{$asset->id}/edit")->assertForbidden();
        $this->postJson('/api/assets', $this->payload())->assertForbidden();
        $this->putJson("/api/assets/{$asset->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/assets/{$asset->id}")->assertForbidden();

        $this->actingAs($this->makeUser('technician', [$this->fa->id]))->deleteJson("/api/assets/{$asset->id}")->assertForbidden();
        $this->actingAs($this->makeUser('inventory_manager', [$this->fa->id]))->get('/assets')->assertOk();
    }

    public function test_create_asset_saves_all_fields_syncs_legacy_names_and_audits(): void
    {
        Storage::fake('local');
        $this->manager();
        $res = $this->post('/api/assets', $this->payload(['image' => UploadedFile::fake()->createWithContent('a.png', $this->pngBytes())]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.asset_tag', 'BIO-0001')->assertJsonPath('data.status_label', 'Operational');

        $asset = Asset::findOrFail($res->json('data.id'));
        $this->assertSame('Patient Monitoring', $asset->category);   // legacy text kept in sync
        $this->assertSame('Philips', $asset->manufacturer);
        $this->assertSame('850000.00', $asset->purchase_cost);
        Storage::disk('local')->assertExists($asset->image_path);
        $this->get("/assets/{$asset->id}/image")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.created', 'subject_id' => $asset->id]);
    }

    public function test_validation_rules(): void
    {
        $this->manager();
        $this->postJson('/api/assets', ['facility_id' => $this->fa->id])->assertStatus(422)
            ->assertJsonValidationErrors(['asset_tag', 'name', 'category_id', 'manufacturer_id', 'model', 'serial_number', 'department_id', 'location_id', 'status', 'asset_type']);

        $this->postJson('/api/assets', $this->payload())->assertCreated();
        $this->postJson('/api/assets', $this->payload(['serial_number' => 'OTHER']))->assertStatus(422)->assertJsonValidationErrors('asset_tag');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BIO-0002']))->assertStatus(422)->assertJsonValidationErrors('serial_number');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BIO-0003', 'serial_number' => 'S3', 'status' => 'disposed']))->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BIO-0003', 'serial_number' => 'S3', 'purchase_cost' => -5]))->assertStatus(422)->assertJsonValidationErrors('purchase_cost');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BIO-0003', 'serial_number' => 'S3', 'purchase_date' => '2999-01-01']))->assertStatus(422)->assertJsonValidationErrors('purchase_date');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BIO-0003', 'serial_number' => 'S3', 'warranty_expiry_at' => '2020-01-01']))->assertStatus(422)->assertJsonValidationErrors('warranty_expiry_at');
        $this->postJson('/api/assets', $this->payload(['asset_tag' => 'BAD TAG!', 'serial_number' => 'S3']))->assertStatus(422)->assertJsonValidationErrors('asset_tag');
    }

    public function test_department_and_location_must_belong_to_the_facility_and_facility_access_is_enforced(): void
    {
        $deptB = Department::create(['facility_id' => $this->fb->id, 'code' => 'D1', 'name' => 'Radiology', 'type' => 'clinical']);
        $locB = Location::create(['facility_id' => $this->fb->id, 'code' => 'L1', 'name' => 'RAD-1', 'type' => 'room']);
        $this->manager();

        $this->postJson('/api/assets', $this->payload(['department_id' => $deptB->id]))->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->postJson('/api/assets', $this->payload(['location_id' => $locB->id]))->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->postJson('/api/assets', $this->payload(['facility_id' => $this->fb->id, 'department_id' => $deptB->id, 'location_id' => $locB->id]))->assertForbidden();

        $foreign = $this->make(['facility_id' => $this->fb->id, 'asset_tag' => 'BIO-F1']);
        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->get("/assets/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/assets/{$foreign->id}", $this->payload())->assertNotFound();
        $this->deleteJson("/api/assets/{$foreign->id}")->assertForbidden(); // managers lack assets.delete
        $this->getJson('/api/assets')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/assets/options?facility_id='.$this->fb->id)->assertForbidden();
    }

    public function test_update_records_changes_keeps_facility_and_blocks_closed_assets(): void
    {
        $this->manager();
        $id = $this->postJson('/api/assets', $this->payload())->assertCreated()->json('data.id');

        $this->putJson("/api/assets/$id", $this->payload(['facility_id' => $this->fb->id, 'status' => 'under_maintenance', 'name' => 'Patient Monitor 2']))
            ->assertOk()->assertJsonPath('data.status', 'operational'); // status is ignored here: lifecycle workflow only
        $asset = Asset::find($id);
        $this->assertSame($this->fa->id, $asset->facility_id);   // facility cannot be moved here
        $history = $this->getJson("/api/assets/$id/history")->assertOk()->json('data');
        $this->assertSame('asset.updated', $history[0]['action']);
        $this->assertContains('name', array_column($history[0]['changes'], 'field'));

        $asset->update(['status' => 'retired']);
        $this->putJson("/api/assets/$id", $this->payload())->assertStatus(422);
        $this->get("/assets/$id/edit")->assertForbidden();
    }

    public function test_delete_is_blocked_when_work_orders_exist(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);
        $asset = $this->make();
        WorkOrder::create(['facility_id' => $this->fa->id, 'asset_id' => $asset->id, 'number' => 'WO-1', 'title' => 'x', 'type' => 'breakdown', 'priority' => 'high']);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(422);
        $this->getJson("/api/assets/{$asset->id}/maintenance")->assertOk()->assertJsonCount(1, 'data');

        WorkOrder::query()->delete();
        $this->deleteJson("/api/assets/{$asset->id}")->assertOk();
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.deleted']);
    }

    public function test_list_search_filters_and_pagination(): void
    {
        $this->manager();
        $this->make(['asset_tag' => 'BIO-0001', 'name' => 'Patient Monitor', 'category_id' => $this->cat->id, 'department_id' => $this->deptA->id, 'serial_number' => 'SN-AAA']);
        $this->make(['asset_tag' => 'BIO-0002', 'name' => 'Ventilator', 'status' => 'out_of_service']);
        foreach (range(3, 14) as $i) {
            $this->make(['asset_tag' => sprintf('BIO-%04d', $i), 'name' => "Pump $i"]);
        }

        $this->getJson('/api/assets')->assertJsonPath('meta.total', 14)->assertJsonCount(10, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/assets?page=2')->assertJsonCount(4, 'data');
        $this->getJson('/api/assets?q=SN-AAA')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/assets?q=Ventil')->assertJsonPath('data.0.asset_tag', 'BIO-0002');
        $this->getJson('/api/assets?status=out_of_service')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/assets?category_id={$this->cat->id}")->assertJsonPath('meta.total', 1);
        $this->getJson("/api/assets?department_id={$this->deptA->id}")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/assets?q=%25')->assertJsonPath('meta.total', 0); // wildcard is escaped
    }

    public function test_dashboard_counts_exclude_closed_assets_and_group_categories(): void
    {
        $this->manager();
        $this->make(['status' => 'operational', 'category_id' => $this->cat->id]);
        $this->make(['status' => 'operational', 'category' => 'Legacy text']);
        $this->make(['status' => 'under_maintenance']);
        $this->make(['status' => 'out_of_service']);
        $this->make(['status' => 'retired']);
        $this->make(['status' => 'disposed']);
        $this->make(['facility_id' => $this->fb->id, 'status' => 'operational']); // not visible to this manager

        $d = $this->getJson('/api/assets/dashboard')->assertOk()->json('data');
        $this->assertSame(4, $d['total']['value']);
        $this->assertSame(2, $d['operational']['value']);
        $this->assertSame(50, $d['operational']['percent']);
        $this->assertSame(25, $d['under_maintenance']['percent']);
        $labels = array_column($d['categories'], 'label');
        $this->assertContains('Patient Monitoring', $labels);
        $this->assertContains('Legacy text', $labels);
        $this->assertContains('Uncategorized', $labels);
    }

    public function test_export_respects_scope_filters_and_neutralises_formulas(): void
    {
        $this->manager();
        $this->make(['asset_tag' => 'BIO-0001', 'name' => '=HYPERLINK("http://x")']);
        $this->make(['facility_id' => $this->fb->id, 'asset_tag' => 'BIO-0002', 'name' => 'Hidden']);
        $csv = $this->get('/api/assets/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('BIO-0001', $csv);
        $this->assertStringNotContainsString('Hidden', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
    }

    public function test_next_tag_suggestion(): void
    {
        $this->manager();
        $this->getJson('/api/assets/next-tag')->assertJsonPath('data.asset_tag', 'BIO-0001');
        $this->make(['asset_tag' => 'BIO-0041']);
        $this->make(['asset_tag' => 'OLD-9999']);
        $this->getJson('/api/assets/next-tag')->assertJsonPath('data.asset_tag', 'BIO-0042');
    }

    public function test_documents_upload_download_and_remove(): void
    {
        Storage::fake('local');
        $this->manager();
        $asset = $this->make();
        $res = $this->post("/api/assets/{$asset->id}/documents", ['title' => 'Warranty', 'file' => UploadedFile::fake()->create('w.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();
        $docId = $res->json('data.id');
        $this->get("/assets/{$asset->id}/documents/$docId")->assertOk();
        $this->getJson("/api/assets/{$asset->id}/documents")->assertJsonCount(1, 'data');

        $this->post("/api/assets/{$asset->id}/documents", ['title' => 'Bad', 'file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("/api/assets/{$asset->id}/documents", ['file' => UploadedFile::fake()->create('w.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('title');

        // other facility cannot download
        $this->actingAs($this->makeUser('facility_manager', [$this->fb->id]))->get("/assets/{$asset->id}/documents/$docId")->assertNotFound();

        $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
        $this->deleteJson("/api/assets/{$asset->id}/documents/$docId")->assertOk();
        $this->assertSame(0, $asset->documents()->count());
    }

    public function test_old_active_status_is_migrated_to_operational(): void
    {
        $this->assertSame('operational', Asset::create(['facility_id' => $this->fa->id, 'asset_tag' => 'X-1', 'name' => 'Default'])->fresh()->status);
    }
}
