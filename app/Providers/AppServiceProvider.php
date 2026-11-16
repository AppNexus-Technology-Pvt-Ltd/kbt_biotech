<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // RBAC: every permission slug is a Gate ability, so @can, can: middleware and $user->can() all work.
        // Returning null (not false) for unknown/denied abilities lets other gates/policies still decide.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });

        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(10)->by('otp:'.$request->session()->getId()),
            Limit::perMinute(30)->by('otp-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
