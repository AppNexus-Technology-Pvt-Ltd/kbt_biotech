<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds roles/permissions and, if none exists yet, ONE initial Super Admin.
     * Credentials come from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD; with no password configured a random
     * one is generated and printed once. Nothing is hardcoded and no demo data is created.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $superAdmin = Role::where('slug', 'super_admin')->first();
        if ($superAdmin->users()->exists()) {
            return;
        }

        $email = strtolower((string) env('SEED_ADMIN_EMAIL', 'admin@kbtruvia.local'));
        $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(16);

        $user = User::create([
            'name' => env('SEED_ADMIN_NAME', 'System Administrator'),
            'email' => $email,
            'username' => 'admin',
            'password' => $password,
            'employee_id' => 'EMP-0001',
            'designation' => 'Super Admin',
            'department' => 'Administration',
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach($superAdmin->id, ['assigned_at' => now()]);

        $this->command?->info("Initial Super Admin created: {$email}");
        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn("Generated password (shown once, change it after first login): {$password}");
        }
    }
}
