<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ForgotPasswordController extends Controller
{
    public function __construct(private PasswordResetService $resets) {}

    public function show(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']], [
            'email.required' => 'Enter your registered email.',
            'email.email' => 'Enter a valid email address.',
        ]);

        $key = 'forgot:'.$request->ip();
        $max = config('kbt.password_reset.max_requests');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $message = 'Too many requests. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 429)
                : back()->withInput()->withErrors(['email' => $message]);
        }
        RateLimiter::hit($key, config('kbt.password_reset.decay_seconds'));

        $this->resets->request($data['email']);

        // Same answer whether or not the address is registered.
        $message = 'If that email is registered, a password reset link has been sent. It expires in '
            .config('kbt.password_reset.ttl_minutes').' minutes.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : back()->with('status', $message);
    }
}
