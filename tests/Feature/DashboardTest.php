<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_dashboard_page_renders_shell_and_sidebar(): void
    {
        $user = $this->makeUser('facility_manager', attributes: ['name' => 'John Smith', 'designation' => 'Biomedical Engineer']);
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('John Smith')->assertSee('Biomedical Engineer')
            ->assertSee('Preventive Maintenance')->assertSee('Collapse')->assertSee('Search assets, work orders, etc...');
    }

    public function test_protected_pages_are_not_cacheable(): void
    {
        $r = $this->actingAs($this->makeUser('technician'))->get('/dashboard');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_empty_database_yields_zeroes_not_fake_data(): void
    {
        $d = $this->actingAs($this->makeUser('admin'))->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $d['stats']['total_equipment']['value']);
        $this->assertSame(0, $d['stats']['open_work_orders']['value']);
        $this->assertSame([], $d['recent_work_orders']);
        $this->assertSame(array_fill(0, 6, 0), $d['trend']['series']['preventive']);
    }

    public function test_statistics_reflect_real_records(): void
    {
        $f = $this->makeFacility('City General');
        Asset::create(['facility_id' => $f->id, 'asset_tag' => 'T1', 'name' => 'Pump', 'next_pm_due_at' => now()->toDateString(), 'next_calibration_due_at' => now()->subDays(3)->toDateString()]);
        Asset::create(['facility_id' => $f->id, 'asset_tag' => 'T2', 'name' => 'Old', 'status' => 'retired']);
        WorkOrder::create(['facility_id' => $f->id, 'number' => 'W1', 'title' => 'a', 'type' => 'breakdown', 'priority' => 'critical', 'status' => 'open']);
        WorkOrder::create(['facility_id' => $f->id, 'number' => 'W2', 'title' => 'b', 'type' => 'preventive', 'priority' => 'high', 'status' => 'completed', 'completed_at' => now()]);

        $d = $this->actingAs($this->makeUser('admin'))->getJson('/api/dashboard')->json('data');
        $this->assertSame(1, $d['stats']['total_equipment']['value']); // retired excluded
        $this->assertSame(1, $d['stats']['open_work_orders']['value']);
        $this->assertSame(1, $d['stats']['open_work_orders']['critical']);
        $this->assertSame(1, $d['stats']['pm_due']['value']);
        $this->assertSame(1, $d['stats']['calibration_due']['overdue']);
        $this->assertSame(1, $d['pm_overview']['completed_this_month']);
        $this->assertSame(1, $d['status_breakdown']['completed']);
        $this->assertSame(1, $d['priority_breakdown']['critical']);
        $this->assertSame(1, end($d['trend']['series']['breakdown']));
    }

    public function test_module_placeholders_show_an_honest_empty_state(): void
    {
        $this->actingAs($this->makeUser('admin'))->get('/vendors')->assertOk()->assertSee('No vendors records yet');
    }

    public function test_search_requires_two_characters_and_escapes_wildcards(): void
    {
        $f = $this->makeFacility('X');
        Asset::create(['facility_id' => $f->id, 'asset_tag' => 'P-1', 'name' => '100% Pump']);
        Asset::create(['facility_id' => $f->id, 'asset_tag' => 'P-2', 'name' => 'Other']);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->getJson('/api/search?q=a')->assertJsonPath('data', []);
        $this->assertCount(0, $this->getJson('/api/search?q='.urlencode('%%'))->json('data'));
        $this->assertCount(1, $this->getJson('/api/search?q='.urlencode('0% P'))->json('data'));
    }
}
