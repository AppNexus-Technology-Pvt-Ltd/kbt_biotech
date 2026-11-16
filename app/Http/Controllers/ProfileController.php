<?php

namespace App\Http\Controllers;

use App\Mail\PasswordChangedMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    private const AUDITED_ACTIONS = ['profile.updated', 'profile.photo_updated', 'auth.password_changed', 'auth.two_factor_changed', 'auth.sessions_revoked', 'auth.password_reset'];

    public function show(Request $request): View
    {
        return view('profile', ['profile' => $this->payload($request->user())]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Employee ID, role, department, facility and status are deliberately not accepted here.
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()\-]{6,19}$/'],
            'preferences' => ['sometimes', 'array'],
            'preferences.notify_email_security' => ['sometimes', 'boolean'],
            'preferences.notify_email_work_orders' => ['sometimes', 'boolean'],
            'preferences.language' => ['sometimes', Rule::in(['en', 'hi'])],
            'preferences.density' => ['sometimes', Rule::in(['comfortable', 'compact'])],
        ], [
            'phone.regex' => 'Enter a valid phone number, e.g. +91 98765 43210.',
            'email.unique' => 'That email is already used by another account.',
        ]);

        $data['email'] = mb_strtolower($data['email']);
        $before = $user->only(['name', 'email', 'phone']) + ['preferences' => $user->preferences ?? []];

        $user->fill(collect($data)->only(['name', 'email', 'phone'])->all());
        if (isset($data['preferences'])) {
            $user->preferences = array_merge(User::DEFAULT_PREFERENCES, $user->preferences ?? [], $data['preferences']);
        }

        $dirty = array_keys($user->getDirty());
        if ($dirty) {
            $after = $user->only(['name', 'email', 'phone']) + ['preferences' => $user->preferences ?? []];
            $user->save();
            $old = $new = [];
            foreach (['name', 'email', 'phone', 'preferences'] as $field) {
                if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                    $old[$field] = $before[$field] ?? null;
                    $new[$field] = $after[$field] ?? null;
                }
            }
            AuditLogger::log('profile.updated', $user, $user, $old, $new);
        }

        return response()->json(['success' => true, 'message' => 'Profile updated.', 'data' => $this->payload($user->refresh())]);
    }

    public function photo(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ], [
            'photo.max' => 'The photo must be 2 MB or smaller.',
            'photo.mimes' => 'Use a JPG, PNG or WebP image.',
        ]);

        $user = $request->user();
        $file = $request->file('photo');
        $path = $file->storeAs('profile-photos', Str::uuid().'.'.$file->guessExtension(), 'local');

        $old = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        AuditLogger::log('profile.photo_updated', $user, $user);

        return response()->json(['success' => true, 'message' => 'Profile photo updated.', 'data' => ['photo_url' => $user->photoUrl()]]);
    }

    /** Authenticated users may view each other's avatars (shown next to names). */
    public function servePhoto(User $user): StreamedResponse
    {
        abort_unless($user->profile_photo_path && Storage::disk('local')->exists($user->profile_photo_path), 404);

        return Storage::disk('local')->response($user->profile_photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.different' => 'The new password must be different from the current one.',
        ]);

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill([
                'password' => $data['password'],
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            AuditLogger::log('auth.password_changed', $user, $user);
        });

        $user->notify(new SecurityNotice('Password changed', 'Your password was changed. Other devices were signed out.'));
        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
        } catch (\Throwable $e) {
            Log::error('Password change confirmation email failed', ['user_id' => $user->id, 'error' => $e::class]);
        }

        return response()->json(['success' => true, 'message' => 'Password changed. Other devices have been signed out.']);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'password' => ['required', 'current_password'],
        ], ['password.current_password' => 'Your password is incorrect.']);

        $user = $request->user();
        $old = $user->two_factor_enabled;
        $user->forceFill(['two_factor_enabled' => (bool) $data['enabled']])->save();
        AuditLogger::log('auth.two_factor_changed', $user, $user, ['enabled' => $old], ['enabled' => $user->two_factor_enabled]);

        return response()->json([
            'success' => true,
            'message' => $user->two_factor_enabled ? 'Two-factor verification enabled.' : 'Two-factor verification disabled.',
            'data' => ['two_factor_enabled' => $user->two_factor_enabled],
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(User $user): array
    {
        $user->loadMissing(['roles:id,slug,name', 'facilities:id,name']);

        return [
            'name' => $user->name,
            'initials' => $user->initials(),
            'photo_url' => $user->photoUrl(),
            'username' => $user->username,
            'employee_id' => $user->employee_id,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->roles->pluck('name')->implode(', ') ?: null,
            'designation' => $user->designation ?: $user->roles->first()?->name,
            'department' => $user->department,
            'facility' => $user->facilities->pluck('name')->implode(', ') ?: ($user->canAccessAllFacilities() ? 'All facilities' : null),
            'status' => $user->status,
            'preferences' => array_merge(User::DEFAULT_PREFERENCES, $user->preferences ?? []),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_login_ip' => $user->last_login_ip,
            'two_factor_enabled' => $user->two_factor_enabled,
            'otp_enforced' => (bool) config('kbt.otp.required'),
            'activity' => AuditLog::query()->where('user_id', $user->id)
                ->whereIn('action', self::AUDITED_ACTIONS)->latest('id')->limit(8)->get(['action', 'created_at', 'ip_address'])
                ->map(fn ($a) => ['action' => $a->action, 'at' => $a->created_at->toIso8601String(), 'ip' => $a->ip_address]),
        ];
    }
}
