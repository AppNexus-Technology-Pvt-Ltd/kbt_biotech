<?php

namespace Tests\Concerns;

use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesUsers
{
    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param  array<int>  $facilityIds */
    protected function makeUser(string $role = 'read_only', array $facilityIds = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('slug', $role)->value('id'), ['assigned_at' => now()]);
        foreach ($facilityIds as $i => $id) {
            $user->facilities()->attach($id, ['is_primary' => $i === 0]);
        }

        return $user;
    }

    protected function makeFacility(string $name): Facility
    {
        return Facility::create(['name' => $name]);
    }

    /** Valid PNG of the given size built without GD. */
    protected function pngBytes(int $size = 80): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\x00".str_repeat("\x11\xB5\xAB", $size);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $size)))
            .$chunk('IEND', '');
    }
}
