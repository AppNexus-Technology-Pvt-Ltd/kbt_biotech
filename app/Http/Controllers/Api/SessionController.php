<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->session()->getId();

        $rows = DB::table('sessions')->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return response()->json(['success' => true, 'data' => $rows->map(fn ($s) => [
            'id' => $this->publicId($s->id),
            'device' => $this->describe((string) $s->user_agent),
            'ip' => $s->ip_address,
            'last_active' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
            'current' => $s->id === $current,
        ])->values()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $current = $request->session()->getId();
        $target = DB::table('sessions')->where('user_id', $request->user()->id)->pluck('id')
            ->first(fn ($sid) => hash_equals($this->publicId($sid), $id));

        if (! $target) {
            return response()->json(['success' => false, 'message' => 'Session not found.'], 404);
        }
        if ($target === $current) {
            return response()->json(['success' => false, 'message' => 'Use Sign out to end your current session.'], 422);
        }

        DB::table('sessions')->where('id', $target)->delete();
        AuditLogger::log('auth.sessions_revoked', $request->user(), null, null, ['scope' => 'single']);

        return response()->json(['success' => true, 'message' => 'Device signed out.']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Your password is incorrect.']);

        $count = DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())->delete();
        AuditLogger::log('auth.sessions_revoked', $request->user(), null, null, ['scope' => 'others', 'count' => $count]);

        return response()->json(['success' => true, 'message' => "Signed out of $count other device(s)."]);
    }

    /** Raw session ids are secrets; clients only ever see this keyed hash. */
    private function publicId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    private function describe(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return "$browser on $os";
    }
}
