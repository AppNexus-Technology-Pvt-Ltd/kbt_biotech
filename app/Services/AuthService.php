<?php

namespace App\Services;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const THROTTLED = 'throttled';
    public const LOCKED = 'locked';
    public const DISABLED = 'disabled';
    public const INACTIVE = 'inactive';

    /**
     * Validate credentials. Does NOT log the user in (OTP may still be required).
     *
     * @return array{status: string, user: ?User, retry_after: int}
     */
    public function attempt(string $login, string $password, Request $request): array
    {
        $login = mb_strtolower(trim($login));
        $ip = (string) $request->ip();
        $max = config('kbt.login.max_attempts');
        $decay = config('kbt.login.decay_seconds');
        $userKey = 'login:'.sha1($login.'|'.$ip);
        $ipKey = 'login-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($userKey, $max) || RateLimiter::tooManyAttempts($ipKey, $max * 4)) {
            $this->record(null, $login, 'throttled', $request);

            return ['status' => self::THROTTLED, 'user' => null,
                'retry_after' => max(RateLimiter::availableIn($userKey), RateLimiter::availableIn($ipKey))];
        }

        $user = User::query()
            ->where(fn ($q) => $q->where('email', $login)->orWhere('username', $login))
            ->first();

        // Constant-ish time: always run a hash check.
        $passwordOk = $user
            ? Hash::check($password, $user->password)
            : Hash::check($password, self::dummyHash());

        if (! $user || ! $passwordOk) {
            RateLimiter::hit($userKey, $decay);
            RateLimiter::hit($ipKey, $decay);
            if ($user && ! $user->isLocked()) {
                $this->registerFailure($user);
            }
            $this->record($user, $login, 'failed', $request);

            return ['status' => self::INVALID, 'user' => null, 'retry_after' => 0];
        }

        // Credentials are right: now it is safe to tell the user why they cannot sign in.
        if ($user->status === User::STATUS_DISABLED) {
            $this->record($user, $login, 'disabled', $request);

            return ['status' => self::DISABLED, 'user' => null, 'retry_after' => 0];
        }
        if ($user->status !== User::STATUS_ACTIVE) {
            $this->record($user, $login, 'inactive', $request);

            return ['status' => self::INACTIVE, 'user' => null, 'retry_after' => 0];
        }
        if ($user->isLocked()) {
            $this->record($user, $login, 'locked', $request);

            return ['status' => self::LOCKED, 'user' => null,
                'retry_after' => (int) now()->diffInSeconds($user->locked_until, false)];
        }

        RateLimiter::clear($userKey);

        return ['status' => self::OK, 'user' => $user, 'retry_after' => 0];
    }

    public function otpRequired(User $user): bool
    {
        return config('kbt.otp.required') || $user->two_factor_enabled;
    }

    public function completeLogin(User $user, bool $remember, Request $request): void
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->record($user, $user->email, 'success', $request);
        Cookie::queue(Cookie::make(config('kbt.session_marker_cookie'), '1', 60 * 24 * 30, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public function logout(Request $request): void
    {
        $user = Auth::user();
        if ($user) {
            AuditLogger::log('auth.logout', $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(config('kbt.session_marker_cookie')));
    }

    public function record(?User $user, ?string $identifier, string $status, Request $request): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'identifier' => $identifier ? mb_substr($identifier, 0, 255) : null,
            'status' => $status,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    private static ?string $dummy = null;

    private static function dummyHash(): string
    {
        return self::$dummy ??= Hash::make(bin2hex(random_bytes(8)));
    }

    private function registerFailure(User $user): void
    {
        $user->increment('failed_login_attempts');
        if ($user->failed_login_attempts >= config('kbt.login.max_attempts')) {
            $user->forceFill([
                'locked_until' => now()->addMinutes(config('kbt.login.lockout_minutes')),
                'failed_login_attempts' => 0,
            ])->save();
            AuditLogger::log('auth.account_locked', $user);
        }
    }
}
