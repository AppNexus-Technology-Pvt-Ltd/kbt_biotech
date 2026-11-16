<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;

class StatusPageController extends Controller
{
    public function sessionExpired()
    {
        // Make sure nothing protected lingers, and stop treating this browser as "had a session".
        Cookie::queue(Cookie::forget(config('kbt.session_marker_cookie')));

        return response()->view('errors.session-expired', [], 401)
            ->header('Cache-Control', 'no-store');
    }

    public function accessDenied(): View
    {
        return view('errors.access-denied', ['message' => 'You do not have permission to access this module. Please contact your administrator if you believe this is an error.']);
    }
}
