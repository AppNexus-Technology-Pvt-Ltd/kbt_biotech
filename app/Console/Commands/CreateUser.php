<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;

class CreateUser extends Command
{
    protected $signature = 'kbt:user:create
        {email : Login email}
        {name : Full name}
        {--role=read_only : Role slug (see roles table)}
        {--facility=* : Facility name to assign (created if missing)}
        {--username= : Optional username for login}
        {--employee-id= : Optional employee id}';

    protected $description = 'Create a user, assign a role and facilities (prompts for the password).';

    public function handle(): int
    {
        $role = Role::where('slug', $this->option('role'))->first();
        if (! $role) {
            $this->error('Unknown role. Available: '.Role::pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $password = promptPassword('Password (min 8, upper/lower/number/symbol)');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['email', 'unique:users,email'], 'password' => [Password::defaults()]]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->argument('name'),
            'email' => strtolower($this->argument('email')),
            'username' => $this->option('username') ?: null,
            'employee_id' => $this->option('employee-id') ?: null,
            'password' => $password,
            'password_changed_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        foreach (array_values($this->option('facility')) as $i => $name) {
            $facility = Facility::firstOrCreate(['name' => $name]);
            $user->facilities()->attach($facility->id, ['is_primary' => $i === 0]);
        }

        $this->info("User {$user->email} created with role {$role->name}.");

        return self::SUCCESS;
    }
}
