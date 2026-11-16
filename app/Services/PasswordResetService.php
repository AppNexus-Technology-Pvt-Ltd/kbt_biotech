<?php

namespace App\Services;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use App\Notifications\SecurityNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    /** Always returns normally so callers cannot reveal whether the email exists. */
    public function request(string $email): void
    {
        $user = User::where('email', mb_strtolower(trim($email)))->first();
        if (! $user || ! $user->isActive()) {
            return;
        }

        $plain = Str::random(64);

        // One live token per email: updateOrInsert on the primary key invalidates any previous link.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $plain), 'created_at' => now()]
        );

        $url = route('password.reset', ['token' => $plain]);
        try {
            Mail::to($user->email)->send(new PasswordResetLinkMail($user, $url, config('kbt.password_reset.ttl_minutes')));
        } catch (\Throwable $e) {
            Log::error('Password reset email delivery failed', ['user_id' => $user->id, 'error' => $e::class]);
        }

        AuditLogger::log('auth.password_reset_requested', $user);
    }

    /** @return object|null the token row if valid and unexpired */
    public function findValid(string $plain): ?object
    {
        if ($plain === '') {
            return null;
        }
        $row = DB::table('password_reset_tokens')->where('token', hash('sha256', $plain))->first();
        if (! $row || ! $row->created_at) {
            return null;
        }
        if (Carbon::parse($row->created_at)->addMinutes(config('kbt.password_reset.ttl_minutes'))->isPast()) {
            DB::table('password_reset_tokens')->where('email', $row->email)->delete();

            return null;
        }

        return $row;
    }

    public function reset(string $plain, string $newPassword): bool
    {
        $user = DB::transaction(function () use ($plain, $newPassword) {
            $row = $this->findValid($plain);
            if (! $row) {
                return null;
            }
            $user = User::where('email', $row->email)->lockForUpdate()->first();
            if (! $user || ! $user->isActive()) {
                return null;
            }

            $user->forceFill([
                'password' => $newPassword, // hashed by cast
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            DB::table('password_reset_tokens')->where('email', $user->email)->delete(); // no reuse
            DB::table('sessions')->where('user_id', $user->id)->delete();               // sign out everywhere

            AuditLogger::log('auth.password_reset', $user);

            return $user;
        });

        if (! $user) {
            return false;
        }

        $user->notify(new SecurityNotice('Password changed', 'Your password was reset successfully.'));
        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
        } catch (\Throwable $e) {
            Log::error('Password change confirmation email failed', ['user_id' => $user->id, 'error' => $e::class]);
        }

        return true;
    }
}
