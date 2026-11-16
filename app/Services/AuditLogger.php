<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Record an audit entry. Never pass passwords, tokens or OTPs in $old/$new.
     *
     * @param  array<string,mixed>|null  $old
     * @param  array<string,mixed>|null  $new
     */
    public static function log(string $action, ?User $user = null, ?Model $subject = null, ?array $old = null, ?array $new = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
