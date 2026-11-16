<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    /** Switch the facility whose data is shown. Empty value = all facilities the user may access. */
    public function switch(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['facility_id' => ['nullable', 'integer']]);
        $id = $data['facility_id'] ?? null;

        if ($id !== null && ! $request->user()->accessibleFacilities()->whereKey($id)->exists()) {
            abort(403);
        }

        $id ? $request->session()->put('facility_id', (int) $id) : $request->session()->forget('facility_id');

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Facility updated.'])
            : redirect()->back();
    }
}
