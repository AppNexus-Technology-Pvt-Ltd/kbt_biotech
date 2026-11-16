<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Department;
use App\Models\EquipmentCategory;
use App\Models\Facility;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AssetToolsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private Facility $fa;

    private Facility $fb;

    private Department $dA;

    private Location $lA;

    private EquipmentCategory $cat;

    private Manufacturer $mfr;

    private const HEADER = 'Asset ID,Equipment Name *,Category Code *,Manufacturer Code *,Model *,Serial Number *,Asset Type,Facility Code *,Department Code *,Location Code *,Status,Purchase Date,Asset Cost,Funding Source,Warranty Expiry,AMC Expiry';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedRbac();
        $this->fa = Facility::create(['name' => 'City General', 'code' => 'H001', 'is_active' => true]);
        $this->fb = Facility::create(['name' => 'Other Hospital', 'code' => 'H002', 'is_active' => true]);
        $this->dA = Department::create(['facility_id' => $this->fa->id, 'code' => 'D1', 'name' => 'ICU', 'type' => 'clinical']);
        $b = Building::create(['facility_id' => $this->fa->id, 'code' => 'B1', 'name' => 'Main Block', 'floors' => 3]);
        $this->lA = Location::create(['facility_id' => $this->fa->id, 'building_id' => $b->id, 'code' => 'L1', 'name' => 'ICU-1', 'type' => 'icu', 'floor' => 1]);
        $this->cat = EquipmentCategory::create(['code' => 'CAT1', 'name' => 'Patient Monitoring']);
        $this->mfr = Manufacturer::create(['code' => 'M1', 'name' => 'Philips']);
    }

    private function manager()
    {
        return $this->actingAs($this->makeUser('facility_manager', [$this->fa->id]));
    }

    private function make(array $over = []): Asset
    {
        return Asset::create(array_merge(['facility_id' => $this->fa->id, 'asset_tag' => 'BIO-'.random_int(1000, 9999), 'name' => 'Ventilator', 'serial_number' => 'S'.random_int(1, 99999)], $over));
    }

    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('assets.csv', implode("\n", array_merge([self::HEADER], $lines)));
    }

    private function good(string $serial = 'SN1', string $tag = ''): string
    {
        return "$tag,Patient Monitor,CAT1,M1,MX450,$serial,movable,H001,D1,L1,operational,2024-03-15,250000,Hospital Fund,2027-03-14,2028-03-14";
    }

    /* ----------------------------------------------------------------- pages */

    public function test_pages_render_and_are_gated(): void
    {
        $this->manager();
        foreach (['/assets/labels', '/assets/labels?ids=1', '/assets/scan', '/assets/import', '/assets/reports'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/assets/register')->assertSee('Print QR');

        $this->actingAs($this->makeUser('technician', [$this->fa->id]));   // no assets.create, no reports.view
        $this->get('/assets/scan')->assertOk();
        $this->get('/assets/import')->assertForbidden();
        $this->get('/assets/reports')->assertForbidden();
        $this->getJson('/api/assets/import/template')->assertForbidden();
        $this->getJson('/api/assets/reports/register')->assertForbidden();
    }

    /* ---------------------------------------------------------------- labels */

    public function test_labels_api_is_scoped_validated_and_audited(): void
    {
        $mine = $this->make(['asset_tag' => 'BIO-0001', 'name' => 'Monitor', 'department_id' => $this->dA->id, 'location_id' => $this->lA->id]);
        $foreign = $this->make(['facility_id' => $this->fb->id, 'asset_tag' => 'BIO-0002']);
        $this->manager();

        $res = $this->postJson('/api/assets/labels', ['ids' => [$mine->id], 'copies' => 2])->assertOk();
        $this->assertSame('BIO-0001', $res->json('data.0.asset_tag'));
        $this->assertStringContainsString('/assets/scan?code=BIO-0001', $res->json('data.0.scan_url'));
        $this->assertSame('ICU', $res->json('data.0.department'));
        $this->assertDatabaseCount('audit_logs', 1);

        // preview does not write audit rows
        $this->postJson('/api/assets/labels', ['ids' => [$mine->id], 'preview' => true])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);

        $this->postJson('/api/assets/labels', ['ids' => [$mine->id, $foreign->id]])->assertStatus(422);
        $this->postJson('/api/assets/labels', ['ids' => []])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->postJson('/api/assets/labels', ['ids' => [$mine->id], 'copies' => 99])->assertStatus(422)->assertJsonValidationErrors('copies');
        $this->postJson('/api/assets/labels', ['ids' => range(1, 201)])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    /* ------------------------------------------------------------------ scan */

    public function test_lookup_accepts_tag_or_scanned_url_and_respects_facility_access(): void
    {
        $this->make(['asset_tag' => 'BIO-0001', 'name' => 'Monitor', 'manufacturer_id' => $this->mfr->id, 'location_id' => $this->lA->id, 'status' => 'under_maintenance']);
        $this->make(['facility_id' => $this->fb->id, 'asset_tag' => 'BIO-0002']);
        $this->manager();

        $this->getJson('/api/assets/lookup?code=BIO-0001')->assertOk()->assertJsonPath('data.name', 'Monitor')->assertJsonPath('data.status_label', 'Under Maintenance')
            ->assertJsonPath('data.manufacturer', 'Philips');
        $this->getJson('/api/assets/lookup?code='.urlencode('http://localhost/assets/scan?code=BIO-0001'))->assertOk()->assertJsonPath('data.asset_tag', 'BIO-0001');
        $this->getJson('/api/assets/lookup?code=%20BIO-0001%20')->assertOk();
        $this->getJson('/api/assets/lookup?code=BIO-0002')->assertNotFound();      // other facility
        $this->getJson('/api/assets/lookup?code=NOPE')->assertNotFound();
        $this->getJson('/api/assets/lookup?code=')->assertStatus(422);
        $this->getJson('/api/assets/lookup?code='.urlencode("BIO-0001' OR 1=1"))->assertNotFound();
    }

    /* ---------------------------------------------------------------- import */

    public function test_template_downloads_as_xlsx(): void
    {
        $this->manager();
        $res = $this->get('/api/assets/import/template')->assertOk();
        $this->assertStringContainsString('asset-import-template.xlsx', $res->headers->get('content-disposition'));
    }

    public function test_import_validates_then_commits_all_rows_and_generates_ids(): void
    {
        $this->make(['asset_tag' => 'BIO-0007']);
        $this->manager();

        $v = $this->postJson('/api/assets/import/validate', ['file' => $this->csv([$this->good('SN1'), $this->good('SN2', 'ENG-1')])])->assertOk()->json('data');
        $this->assertSame([2, 2, 0], [$v['total'], $v['valid'], $v['invalid']]);
        $this->assertNotNull($v['token']);
        $this->assertSame(1, Asset::count());   // validation alone imports nothing

        $this->postJson('/api/assets/import/confirm', ['token' => $v['token']])->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(3, Asset::count());
        $this->assertDatabaseHas('assets', ['asset_tag' => 'BIO-0008', 'serial_number' => 'SN1', 'category' => 'Patient Monitoring', 'manufacturer' => 'Philips', 'status' => 'operational']);
        $this->assertDatabaseHas('assets', ['asset_tag' => 'ENG-1', 'purchase_cost' => '250000.00', 'location_id' => $this->lA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.imported']);

        // a token works once
        $this->postJson('/api/assets/import/confirm', ['token' => $v['token']])->assertStatus(410);
    }

    public function test_import_reports_every_row_error_and_imports_nothing(): void
    {
        $this->make(['asset_tag' => 'BIO-0001', 'manufacturer_id' => $this->mfr->id, 'serial_number' => 'DUP']);
        $this->manager();
        $lines = [
            $this->good('SN1'),
            'BIO-0001,Name,CAT1,M1,Model,SN9,movable,H001,D1,L1,,,,,,',          // existing asset id
            ',Name,NOPE,M1,Model,SN8,movable,H001,D1,L1,,,,,,',                  // bad category
            ',Name,CAT1,M1,Model,DUP,movable,H001,D1,L1,,,,,,',                  // serial already registered
            ',Name,CAT1,M1,Model,SN1,movable,H001,D1,L1,,,,,,',                  // serial repeated in file
            ',Name,CAT1,M1,Model,SN7,movable,H002,D1,L1,,,,,,',                  // facility not accessible
            ',,CAT1,M1,Model,SN6,movable,H001,D1,L1,,,,,,',                      // missing name
            ',Name,CAT1,M1,Model,SN5,fixed,H001,D1,L1,retired,2999-01-01,-5,,,', // type, status, date, cost
            ',Name,CAT1,M1,Model,SN4,movable,H001,D1,L1,,2024-05-01,,,2023-01-01,', // warranty before purchase
        ];
        $v = $this->postJson('/api/assets/import/validate', ['file' => $this->csv($lines)])->assertOk()->json('data');

        $this->assertSame(9, $v['total']);
        $this->assertSame(1, $v['valid']);
        $this->assertSame(8, $v['invalid']);
        $this->assertNull($v['token']);
        $byRow = collect($v['errors'])->keyBy('row');
        $this->assertStringContainsString('already exists', $byRow[3]['messages'][0]);
        $this->assertStringContainsString('Category code', $byRow[4]['messages'][0]);
        $this->assertStringContainsString('already registered', $byRow[5]['messages'][0]);
        $this->assertStringContainsString('repeated', $byRow[6]['messages'][0]);
        $this->assertStringContainsString('do not have access', $byRow[7]['messages'][0]);
        $this->assertGreaterThanOrEqual(3, count($byRow[9]['messages']));
        $this->assertStringContainsString('before the purchase date', $byRow[10]['messages'][0]);
        $this->assertSame(1, Asset::count());
        $this->postJson('/api/assets/import/confirm', ['token' => 'not-a-token'])->assertStatus(410);
    }

    public function test_import_rejects_bad_files(): void
    {
        $this->manager();
        $this->postJson('/api/assets/import/validate', [])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/assets/import/validate', ['file' => UploadedFile::fake()->create('a.pdf', 5)])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/assets/import/validate', ['file' => UploadedFile::fake()->createWithContent('a.csv', "Foo,Bar\n1,2")])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Missing required column'));
        $this->postJson('/api/assets/import/validate', ['file' => UploadedFile::fake()->createWithContent('a.csv', self::HEADER)])->assertStatus(422)->assertJsonPath('message', 'The file has no data rows.');
        $this->postJson('/api/assets/import/validate', ['file' => UploadedFile::fake()->createWithContent('a.xlsx', 'not really a zip')])->assertStatus(422);
        $big = array_fill(0, 1001, $this->good('X'));
        $this->postJson('/api/assets/import/validate', ['file' => $this->csv($big)])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '1000 rows'));
    }

    public function test_import_reads_a_real_xlsx_and_a_token_is_bound_to_its_user(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        $w->addRow(Row::fromValues(explode(',', self::HEADER)));
        $w->addRow(Row::fromValues(['', 'Defibrillator', 'cat1', 'm1', 'LP15', 'XL-1', 'Movable', 'H001', 'D1', 'L1', 'Out of Service', '2024-02-01', 120000, '', '', '']));
        $w->close();

        $owner = $this->makeUser('facility_manager', [$this->fa->id]);
        $this->actingAs($owner);
        $v = $this->postJson('/api/assets/import/validate', ['file' => new UploadedFile($path, 'assets.xlsx', null, null, true)])->assertOk()->json('data');
        $this->assertSame(1, $v['valid'], json_encode($v['errors']));

        $this->actingAs($this->makeUser('admin'));
        $this->postJson('/api/assets/import/confirm', ['token' => $v['token']])->assertForbidden();   // someone else's import
        $this->actingAs($owner);
        $this->postJson('/api/assets/import/confirm', ['token' => $v['token']])->assertOk();
        $this->assertDatabaseHas('assets', ['name' => 'Defibrillator', 'status' => 'out_of_service', 'asset_type' => 'movable']);
    }

    public function test_confirm_rechecks_the_data_in_case_it_changed(): void
    {
        $this->manager();
        $v = $this->postJson('/api/assets/import/validate', ['file' => $this->csv([$this->good('SN1', 'BIO-0050')])])->json('data');
        $this->make(['asset_tag' => 'BIO-0050']);   // someone registers the same ID meanwhile
        $this->postJson('/api/assets/import/confirm', ['token' => $v['token']])->assertStatus(409);
        $this->assertSame(1, Asset::count());
    }

    /* --------------------------------------------------------------- reports */

    public function test_reports_run_are_scoped_and_validate_filters(): void
    {
        $this->make(['asset_tag' => 'BIO-0001', 'department_id' => $this->dA->id, 'location_id' => $this->lA->id, 'purchase_cost' => 1000, 'status' => 'operational',
            'warranty_expiry_at' => now()->addDays(30)->format('Y-m-d'), 'purchase_date' => now()->subYears(2)->format('Y-m-d')]);
        $this->make(['asset_tag' => 'BIO-0002', 'department_id' => $this->dA->id, 'purchase_cost' => 500, 'status' => 'retired']);
        $this->make(['asset_tag' => 'BIO-0003', 'amc_expiry_at' => now()->addDays(200)->format('Y-m-d')]);
        $this->make(['facility_id' => $this->fb->id, 'asset_tag' => 'BIO-0099']);
        $this->manager();

        $reg = $this->getJson('/api/assets/reports/register')->assertOk()->json('data');
        $this->assertSame(3, $reg['total']);
        $this->assertNotContains('BIO-0099', array_column($reg['rows'], 'asset_tag'));
        $this->getJson('/api/assets/reports/register?status=retired')->assertJsonPath('data.total', 1);

        $dept = $this->getJson('/api/assets/reports/department')->json('data');
        $row = collect($dept['rows'])->firstWhere('department', 'ICU');
        $this->assertSame(1, $row['assets']);               // retired asset excluded
        $this->assertSame('1000.00', $row['value']);

        $w = $this->getJson('/api/assets/reports/warranty')->json('data');
        $this->assertSame(['BIO-0001'], array_column($w['rows'], 'asset_tag'));          // default window: next 90 days
        $this->assertSame(30, $w['rows'][0]['warranty_days']);
        $wide = $this->getJson('/api/assets/reports/warranty?to='.now()->addDays(365)->format('Y-m-d'))->json('data');
        $this->assertCount(2, $wide['rows']);
        $this->getJson('/api/assets/reports/warranty?from=2030-01-02&to=2030-01-01')->assertStatus(422);

        $loc = $this->getJson('/api/assets/reports/location')->json('data');
        $this->assertSame('Main Block', collect($loc['rows'])->firstWhere('asset_tag', 'BIO-0001')['building']);

        $life = $this->getJson('/api/assets/reports/lifecycle')->json('data');
        $this->assertSame(1, collect($life['summary'])->firstWhere('label', 'Retired')['count']);
        $this->assertGreaterThan(1.9, collect($life['rows'])->firstWhere('asset_tag', 'BIO-0001')['age']);

        $this->getJson('/api/assets/reports/nonsense')->assertNotFound();
        $this->getJson('/api/assets/reports/register?facility_id='.$this->fb->id)->assertForbidden();
    }

    public function test_report_csv_export_is_complete_and_formula_safe(): void
    {
        foreach (range(1, 250) as $i) {
            $this->make(['asset_tag' => sprintf('BIO-%04d', $i), 'name' => $i === 1 ? '=SUM(1+1)' : "Item $i"]);
        }
        $this->manager();
        $json = $this->getJson('/api/assets/reports/register')->json('data');
        $this->assertSame(250, $json['total']);
        $this->assertSame(200, $json['shown']);

        $csv = $this->get('/api/assets/reports/register?format=csv')->assertOk()->streamedContent();
        $this->assertSame(251, count(array_filter(explode("\n", trim($csv)))));         // header + all 250
        $this->assertStringContainsString("'=SUM(1+1)", $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.report_generated']);
    }

    public function test_auditor_can_run_reports_but_not_import(): void
    {
        $this->make();
        $this->actingAs($this->makeUser('auditor'));
        $this->getJson('/api/assets/reports/register')->assertOk();
        $this->get('/assets/reports')->assertOk();
        $this->postJson('/api/assets/import/validate', ['file' => $this->csv([$this->good()])])->assertForbidden();
    }
}
