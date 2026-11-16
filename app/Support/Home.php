<?php

namespace App\Support;

use App\Models\User;

class Home
{
    /** Landing page for a user, based on what their role allows. */
    public static function urlFor(?User $user): string
    {
        if (! $user) {
            return route('login');
        }
        if ($user->hasPermission('dashboard.view')) {
            return route('dashboard');
        }
        foreach (config('kbt.modules') as $slug => [, $path, $permission]) {
            if ($user->hasPermission($permission)) {
                return url($path);
            }
        }

        return route('profile');
    }
}
