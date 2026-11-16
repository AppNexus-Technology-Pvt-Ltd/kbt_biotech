<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';
    public const MISSING = 'missing';

    /**
     * Generate and send a fresh OTP, invalidating any previous one.
     *
     * @return array{sent: bool, retry_after: int}
     */
    public function issue(User $user, string $purpose = 'login'): array
    {
        $retryAfter = $this->resendRemaining($user, $purpose);
        if ($retryAfter > 0) {
            return ['sent' => false, 'retry_after' => $retryAfter];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $purpose, $code) {
            OtpVerification::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OtpVerification::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'channel' => 'email',
                'destination' => $user->email,
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => now()->addSeconds(config('kbt.otp.ttl_seconds')),
                'created_at' => now(),
            ]);
        });

        try {
            Mail::to($user->email)->send(new OtpMail($user, $code, (int) ceil(config('kbt.otp.ttl_seconds') / 60)));
        } catch (\Throwable $e) {
            // Log the failure class only: never the code or message body.
            Log::error('OTP email delivery failed', ['user_id' => $user->id, 'error' => $e::class]);
        }

        return ['sent' => true, 'retry_after' => (int) config('kbt.otp.resend_cooldown_seconds')];
    }

    /** @return array{status: string, attempts_left: int} */
    public function verify(User $user, string $code, string $purpose = 'login'): array
    {
        return DB::transaction(function () use ($user, $code, $purpose) {
            $otp = OtpVerification::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $max = (int) config('kbt.otp.max_attempts');

            if (! $otp) {
                return ['status' => self::MISSING, 'attempts_left' => 0];
            }
            if ($otp->expires_at->isPast()) {
                $otp->update(['consumed_at' => now()]);

                return ['status' => self::EXPIRED, 'attempts_left' => 0];
            }
            if ($otp->attempts >= $max) {
                $otp->update(['consumed_at' => now()]);

                return ['status' => self::LOCKED, 'attempts_left' => 0];
            }

            if (hash_equals($otp->code_hash, $this->hash($code))) {
                $otp->update(['consumed_at' => now()]); // single use

                return ['status' => self::OK, 'attempts_left' => $max - $otp->attempts];
            }

            $otp->increment('attempts');
            $left = max(0, $max - $otp->attempts);
            if ($left === 0) {
                $otp->update(['consumed_at' => now()]);

                return ['status' => self::LOCKED, 'attempts_left' => 0];
            }

            return ['status' => self::INVALID, 'attempts_left' => $left];
        });
    }

    /** @return array{expires_in: int, resend_in: int} */
    public function state(User $user, string $purpose = 'login'): array
    {
        $otp = OtpVerification::where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();

        return [
            'expires_in' => $otp ? max(0, (int) now()->diffInSeconds($otp->expires_at, false)) : 0,
            'resend_in' => $this->resendRemaining($user, $purpose),
        ];
    }

    private function resendRemaining(User $user, string $purpose): int
    {
        $last = OtpVerification::where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->first();
        if (! $last) {
            return 0;
        }
        $elapsed = (int) $last->created_at->diffInSeconds(now());

        return max(0, (int) config('kbt.otp.resend_cooldown_seconds') - $elapsed);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 2)).mb_substr($local, -1);

        return $visible.'@'.$domain;
    }
}
