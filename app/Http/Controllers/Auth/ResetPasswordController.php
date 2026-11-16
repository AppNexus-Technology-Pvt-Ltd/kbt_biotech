<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ResetPasswordController extends Controller
{
    public function __construct(private PasswordResetService $resets) {}

    public function show(string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'valid' => $this->resets->findValid($token) !== null,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if (! $this->resets->reset($data['token'], $data['password'])) {
            $message = 'This reset link is invalid or has expired. Request a new one.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message, 'redirect' => route('password.request')], 422)
                : redirect()->route('password.request')->withErrors(['email' => $message]);
        }

        $message = 'Your password has been reset. Sign in with your new password.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, 'data' => ['redirect' => route('login')]])
            : redirect()->route('login')->with('status', $message);
    }
}
