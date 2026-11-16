<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard');
    }

    public function data(Request $request, DashboardService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->build($request->user())]);
    }
}
