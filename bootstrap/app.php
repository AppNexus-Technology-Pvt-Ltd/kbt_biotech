<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Home;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$wantsJson = fn (Request $request): bool => $request->expectsJson() || $request->is('api/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'nostore' => NoStore::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => Home::urlFor($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson): void {
        // Not signed in: JSON clients get 401; browsers go to "session expired" if they previously had a session.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            $expired = $request->cookie(config('kbt.session_marker_cookie')) !== null;
            if ($wantsJson($request)) {
                return response()->json([
                    'success' => false,
                    'code' => $expired ? 'session_expired' : 'unauthenticated',
                    'message' => $expired ? 'Your session has expired. Please sign in again.' : 'Authentication required.',
                    'redirect' => $expired ? route('session.expired') : route('login'),
                ], 401);
            }

            return redirect()->route($expired ? 'session.expired' : 'login');
        });

        // Authenticated but not permitted.
        $forbidden = function (Throwable $e, Request $request) use ($wantsJson) {
            $message = 'You do not have permission to access this resource.';
            if ($wantsJson($request)) {
                return response()->json(['success' => false, 'code' => 'forbidden', 'message' => $message], 403);
            }

            return response()->view('errors.access-denied', ['message' => $message], 403);
        };
        $exceptions->render(fn (AuthorizationException $e, Request $r) => $forbidden($e, $r));
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $r) => $forbidden($e, $r));

        // CSRF token mismatch (419) normally means the session ended while a form was open.
        $exceptions->render(function (HttpException $e, Request $request) use ($wantsJson) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            $expired = $request->cookie(config('kbt.session_marker_cookie')) !== null;
            if ($wantsJson($request)) {
                return response()->json([
                    'success' => false,
                    'code' => 'session_expired',
                    'message' => 'Your session has expired. Please sign in again.',
                    'redirect' => route($expired ? 'session.expired' : 'login'),
                ], 419);
            }

            return $expired
                ? redirect()->route('session.expired')
                : redirect()->route('login')->withErrors(['credentials' => 'This page expired. Please try again.']);
        });
    })->create();
