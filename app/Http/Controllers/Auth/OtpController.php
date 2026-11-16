<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Support\Home;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function __construct(private OtpService $otp, private AuthService $auth) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->expired($request);
        }
        $state = $this->otp->state($user);

        return view('auth.otp', [
            'destination' => OtpService::mask($user->email),
            'expiresIn' => $state['expires_in'],
            'resendIn' => $state['resend_in'],
            'ttl' => config('kbt.otp.ttl_seconds'),
        ]);
    }

    public function send(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->expired($request);
        }

        $result = $this->otp->issue($user);
        if (! $result['sent']) {
            $message = 'Please wait '.$result['retry_after'].' seconds before requesting another code.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message, 'data' => ['retry_after' => $result['retry_after']]], 429)
                : back()->withErrors(['code' => $message]);
        }

        $message = 'A new verification code has been sent.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, 'data' => [
                'expires_in' => config('kbt.otp.ttl_seconds'), 'resend_in' => $result['retry_after'],
            ]])
            : back()->with('status', $message);
    }

    public function verify(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->expired($request);
        }

        $data = $request->validate(['code' => ['required', 'digits:6']], [
            'code.required' => 'Enter the 6-digit code.',
            'code.digits' => 'The code must be exactly 6 digits.',
        ]);

        $result = $this->otp->verify($user, $data['code']);

        if ($result['status'] === OtpService::OK) {
            $remember = (bool) $request->session()->get('otp.pending.remember');
            $request->session()->forget('otp.pending');
            $this->auth->completeLogin($user, $remember, $request);
            $url = redirect()->intended(Home::urlFor($user))->getTargetUrl();

            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Verified.', 'data' => ['redirect' => $url]])
                : redirect($url);
        }

        $this->auth->record($user, $user->email, 'otp_failed', $request);

        $message = match ($result['status']) {
            OtpService::INVALID => 'Incorrect code. '.$result['attempts_left'].' attempt(s) remaining.',
            OtpService::EXPIRED => 'This code has expired. Request a new one.',
            OtpService::LOCKED => 'Too many incorrect attempts. Request a new code.',
            default => 'No active code. Request a new one.',
        };

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message, 'data' => ['status' => $result['status']]], 422)
            : back()->withErrors(['code' => $message]);
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('otp.pending');
        if (! $pending || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget('otp.pending');

            return null;
        }
        $user = User::find($pending['user_id']);

        return $user && $user->isActive() ? $user : null;
    }

    private function expired(Request $request): JsonResponse|RedirectResponse
    {
        $message = 'Your verification session expired. Please sign in again.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message, 'redirect' => route('login')], 401)
            : redirect()->route('login')->withErrors(['credentials' => $message]);
    }
}
