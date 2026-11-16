<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ends the session of a user who was disabled / deactivated while signed in. */
class EnsureUserIsActive
{
    public function __construct(private AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (! $user->isActive())) {
            $this->auth->logout($request);
            $message = 'Your account is no longer active. Contact your administrator.';

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'code' => 'account_inactive', 'message' => $message], 403);
            }

            return redirect()->route('login')->withErrors(['credentials' => $message]);
        }

        return $next($request);
    }
}
