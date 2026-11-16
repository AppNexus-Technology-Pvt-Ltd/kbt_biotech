<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to re-run after deploys. Roles are normal rows: administrators may change which
 * permissions they hold (permission_role) without a code change. Re-running only ADDS missing grants
 * for system roles it manages and never removes grants an administrator added.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [module, name] */
    public const PERMISSIONS = [
        'dashboard.view' => ['dashboard', 'View dashboard'],
        'organization.view' => ['organization', 'View organization setup'],
        'organization.manage' => ['organization', 'Manage organization setup'],
        'assets.view' => ['assets', 'View assets'],
        'assets.create' => ['assets', 'Create assets'],
        'assets.edit' => ['assets', 'Edit assets'],
        'assets.delete' => ['assets', 'Delete assets'],
        'assets.approve' => ['assets', 'Approve asset transfers and retire or dispose assets'],
        'installations.view' => ['installations', 'View installations'],
        'installations.manage' => ['installations', 'Manage installations'],
        'installations.approve' => ['installations', 'Approve and commission installations'],
        'contracts.view' => ['contracts', 'View contracts'],
        'contracts.manage' => ['contracts', 'Manage contracts'],
        'pm.view' => ['preventive_maintenance', 'View preventive maintenance'],
        'pm.manage' => ['preventive_maintenance', 'Manage preventive maintenance'],
        'work_orders.view' => ['work_orders', 'View work orders'],
        'work_orders.create' => ['work_orders', 'Create work orders'],
        'work_orders.assign' => ['work_orders', 'Assign work orders'],
        'work_orders.update' => ['work_orders', 'Update work orders'],
        'calibration.view' => ['calibration', 'View calibration'],
        'calibration.manage' => ['calibration', 'Manage calibration'],
        'inventory.view' => ['inventory', 'View inventory'],
        'inventory.manage' => ['inventory', 'Manage inventory'],
        'vendors.view' => ['vendors', 'View vendors'],
        'vendors.manage' => ['vendors', 'Manage vendors'],
        'analytics.view' => ['analytics', 'View analytics'],
        'reports.view' => ['reports', 'View reports'],
        'audit.view' => ['administration', 'View audit logs'],
        'users.manage' => ['administration', 'Manage users'],
        'roles.manage' => ['administration', 'Manage roles and permissions'],
        'settings.manage' => ['administration', 'Manage settings'],
        'facilities.access_all' => ['administration', 'Access data of all facilities'],
    ];

    /** slug => [name, description, permissions ('*' = all, '!x' = all except x)] */
    public const ROLES = [
        'super_admin' => ['Super Admin', 'Full system access, including role configuration.', ['*']],
        'admin' => ['Admin', 'Administers users, settings and all facilities.', ['*', '!roles.manage']],
        'facility_manager' => ['Facility Manager', 'Manages operations within assigned facilities.', [
            'dashboard.view', 'organization.view', 'organization.manage', 'assets.approve', 'installations.approve', 'assets.view', 'assets.create', 'assets.edit', 'installations.view', 'installations.manage',
            'contracts.view', 'pm.view', 'pm.manage', 'work_orders.view', 'work_orders.create', 'work_orders.assign',
            'work_orders.update', 'calibration.view', 'calibration.manage', 'inventory.view', 'vendors.view',
            'analytics.view', 'reports.view',
        ]],
        'biomedical_engineer' => ['Biomedical Engineer', 'Maintains and calibrates biomedical equipment.', [
            'dashboard.view', 'assets.view', 'assets.create', 'assets.edit', 'installations.view', 'installations.manage', 'pm.view', 'pm.manage',
            'work_orders.view', 'work_orders.create', 'work_orders.update', 'calibration.view', 'calibration.manage',
            'inventory.view', 'reports.view',
        ]],
        'technician' => ['Technician', 'Executes assigned work orders.', [
            'dashboard.view', 'assets.view', 'pm.view', 'work_orders.view', 'work_orders.update', 'calibration.view', 'inventory.view',
        ]],
        'inventory_manager' => ['Inventory Manager', 'Owns spare parts and consumables.', [
            'dashboard.view', 'assets.view', 'inventory.view', 'inventory.manage', 'vendors.view', 'reports.view',
        ]],
        'procurement_manager' => ['Procurement Manager', 'Owns vendors and contracts.', [
            'dashboard.view', 'contracts.view', 'contracts.manage', 'vendors.view', 'vendors.manage', 'inventory.view', 'reports.view',
        ]],
        'auditor' => ['Auditor', 'Read-only access for compliance review.', [
            'dashboard.view', 'organization.view', 'assets.view', 'installations.view', 'contracts.view', 'pm.view', 'work_orders.view',
            'calibration.view', 'inventory.view', 'vendors.view', 'analytics.view', 'reports.view', 'audit.view', 'facilities.access_all',
        ]],
        'read_only' => ['Read-Only User', 'View-only access to core records.', [
            'dashboard.view', 'assets.view', 'pm.view', 'work_orders.view', 'calibration.view',
        ]],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$module, $name]) {
            Permission::updateOrCreate(['slug' => $slug], ['module' => $module, 'name' => $name]);
        }
        $all = Permission::pluck('id', 'slug');

        foreach (self::ROLES as $slug => [$name, $description, $grants]) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'is_system' => true]);

            $ids = collect();
            foreach ($grants as $grant) {
                if ($grant === '*') {
                    $ids = $ids->merge($all->values());
                } elseif (str_starts_with($grant, '!')) {
                    $ids = $ids->diff([$all[substr($grant, 1)] ?? null]);
                } else {
                    $ids->push($all[$grant]);
                }
            }
            // Only for a brand-new role do we grant everything; afterwards we just add newly introduced permissions.
            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync($ids->unique()->all());
            } else {
                $role->permissions()->syncWithoutDetaching($ids->unique()->all());
            }
        }

        // Admin access to all facilities is a permission like any other.
        foreach (['super_admin', 'admin'] as $slug) {
            Role::where('slug', $slug)->first()->permissions()->syncWithoutDetaching([$all['facilities.access_all']]);
        }
    }
}
