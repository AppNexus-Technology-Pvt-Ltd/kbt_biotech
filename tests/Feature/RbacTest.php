<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_all_nine_roles_and_core_permissions_exist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['super_admin', 'admin', 'facility_manager', 'biomedical_engineer', 'technician', 'inventory_manager', 'procurement_manager', 'auditor', 'read_only'],
            Role::pluck('slug')->all()
        );
        foreach (['dashboard.view', 'assets.view', 'assets.create', 'assets.edit', 'assets.delete', 'work_orders.view', 'work_orders.create', 'work_orders.assign', 'work_orders.update', 'reports.view', 'users.manage', 'settings.manage'] as $slug) {
            $this->assertTrue(Permission::where('slug', $slug)->exists(), $slug);
        }
    }

    public function test_permission_matrix_per_role(): void
    {
        $cases = [
            'super_admin' => ['settings.manage' => true, 'roles.manage' => true, 'assets.delete' => true],
            'admin' => ['settings.manage' => true, 'users.manage' => true, 'roles.manage' => false],
            'facility_manager' => ['work_orders.assign' => true, 'settings.manage' => false, 'users.manage' => false],
            'biomedical_engineer' => ['assets.edit' => true, 'work_orders.assign' => false, 'assets.delete' => false],
            'technician' => ['work_orders.update' => true, 'work_orders.create' => false, 'assets.create' => false, 'reports.view' => false],
            'auditor' => ['reports.view' => true, 'assets.edit' => false, 'work_orders.update' => false],
            'read_only' => ['dashboard.view' => true, 'assets.edit' => false, 'work_orders.create' => false],
        ];
        foreach ($cases as $role => $expectations) {
            $user = $this->makeUser($role);
            foreach ($expectations as $permission => $expected) {
                $this->assertSame($expected, $user->can($permission), "$role / $permission");
            }
        }
    }

    public function test_module_pages_are_gated_by_backend_permissions(): void
    {
        $tech = $this->makeUser('technician');
        $this->actingAs($tech)->get('/work-orders')->assertOk();
        $this->get('/settings')->assertStatus(403)->assertSee('Access Denied')->assertSee('Go to Dashboard');
        $this->get('/reports')->assertStatus(403);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get('/settings')->assertOk();
    }

    public function test_sidebar_only_lists_permitted_modules(): void
    {
        $html = $this->actingAs($this->makeUser('technician'))->get('/dashboard')->getContent();
        $this->assertStringContainsString('Work Orders', $html);
        $this->assertStringNotContainsString('Settings', $html);
        $this->assertStringNotContainsString('Vendors', $html);
    }

    public function test_api_returns_403_json_when_permission_missing(): void
    {
        $proc = $this->makeUser('procurement_manager'); // has dashboard.view
        $nobody = $this->makeUser('read_only');
        $nobody->roles()->detach();

        $this->actingAs($nobody)->getJson('/api/dashboard')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->actingAs($proc)->getJson('/api/dashboard')->assertOk();
    }

    public function test_user_without_dashboard_permission_lands_on_an_allowed_page(): void
    {
        $user = $this->makeUser('read_only');
        Role::where('slug', 'read_only')->first()->permissions()->detach(Permission::where('slug', 'dashboard.view')->value('id'));

        $this->actingAs($user)->get('/dashboard')->assertStatus(403);
        $this->post('/logout');
        $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertRedirect(url('/assets'));
    }

    public function test_roles_are_configurable_data_not_hardcoded(): void
    {
        $user = $this->makeUser('technician');
        $this->assertFalse($user->can('reports.view'));

        Role::where('slug', 'technician')->first()->permissions()->attach(Permission::where('slug', 'reports.view')->value('id'));
        $user->flushPermissionCache();
        $this->assertTrue($user->can('reports.view'));
        $this->actingAs($user)->get('/reports')->assertOk();
    }

    public function test_me_and_permissions_endpoints(): void
    {
        $user = $this->makeUser('technician');
        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'technician')->assertJsonFragment(['work_orders.update']);
        $this->getJson('/api/permissions')->assertOk()->assertJsonFragment(['dashboard.view']);
    }

    /* ----------------------------------------------------------- facility scoping */

    private function data(): array
    {
        $a = $this->makeFacility('City General');
        $b = $this->makeFacility('Riverside Clinic');
        foreach ([[$a, 'A-1', 'Ventilator A'], [$a, 'A-2', 'Monitor A'], [$b, 'B-1', 'Ventilator B']] as [$f, $tag, $name]) {
            Asset::create(['facility_id' => $f->id, 'asset_tag' => $tag, 'name' => $name, 'next_pm_due_at' => now()->toDateString()]);
        }
        WorkOrder::create(['facility_id' => $a->id, 'number' => 'WO-A', 'title' => 'Fix A', 'type' => 'breakdown', 'priority' => 'critical']);
        WorkOrder::create(['facility_id' => $b->id, 'number' => 'WO-B', 'title' => 'Fix B', 'type' => 'preventive', 'priority' => 'low']);

        return [$a, $b];
    }

    public function test_users_only_see_data_from_their_own_facilities(): void
    {
        [$a, $b] = $this->data();
        $engineer = $this->makeUser('biomedical_engineer', [$a->id]);

        $d = $this->actingAs($engineer)->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $d['stats']['total_equipment']['value']);
        $this->assertSame(1, $d['stats']['open_work_orders']['value']);
        $this->assertSame(['WO-A'], array_column($d['recent_work_orders'], 'number'));

        $search = $this->getJson('/api/search?q=Ventilator')->json('data');
        $this->assertCount(1, $search);
        $this->assertSame('Ventilator A', $search[0]['title']);
    }

    public function test_user_cannot_switch_to_an_unassigned_facility(): void
    {
        [$a, $b] = $this->data();
        $engineer = $this->makeUser('biomedical_engineer', [$a->id]);

        $this->actingAs($engineer)->postJson('/facility', ['facility_id' => $b->id])->assertStatus(403);
        $this->postJson('/facility', ['facility_id' => $a->id])->assertOk();
        $this->assertSame($a->id, session('facility_id'));
    }

    public function test_a_tampered_session_facility_is_ignored(): void
    {
        [$a, $b] = $this->data();
        $engineer = $this->makeUser('biomedical_engineer', [$a->id]);

        $d = $this->actingAs($engineer)->withSession(['facility_id' => $b->id])->getJson('/api/dashboard')->json('data');
        $this->assertSame(2, $d['stats']['total_equipment']['value']);
        $this->assertNull($d['facility']);
    }

    public function test_admin_sees_all_facilities_and_can_narrow_to_one(): void
    {
        [$a, $b] = $this->data();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->getJson('/api/dashboard')->assertJsonPath('data.stats.total_equipment.value', 3);
        $this->postJson('/facility', ['facility_id' => $b->id])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('data.stats.total_equipment.value', 1)->assertJsonPath('data.facility.name', 'Riverside Clinic');
    }

    public function test_user_with_no_facility_assignment_sees_nothing(): void
    {
        $this->data();
        $orphan = $this->makeUser('facility_manager');

        $this->actingAs($orphan)->getJson('/api/dashboard')->assertJsonPath('data.stats.total_equipment.value', 0)
            ->assertJsonPath('data.stats.open_work_orders.value', 0);
    }

    public function test_dashboard_only_includes_widgets_the_role_may_see(): void
    {
        $this->data();
        $inv = $this->makeUser('procurement_manager'); // dashboard.view but neither assets.view nor work_orders.view

        $d = $this->actingAs($inv)->getJson('/api/dashboard')->json('data');
        $this->assertSame([], $d['stats']);
        $this->assertSame([], $d['recent_work_orders']);
        $this->assertNull($d['trend']);
    }
}
