<?php

namespace App\Http\Controllers\Api\Org;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dropdown sources for the organization forms, already limited to what the caller may access. */
class OptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $facilityId = $request->integer('facility_id') ?: null;
        if ($facilityId) {
            abort_unless($user->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
        }

        $facilities = $user->accessibleFacilities()->get(['id', 'name']);
        $scope = $facilityId ? [$facilityId] : $facilities->pluck('id')->all();

        return response()->json(['success' => true, 'data' => [
            'facilities' => $facilities,
            'buildings' => Building::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get(['id', 'facility_id', 'name', 'code', 'floors']),
            'departments' => Department::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('name')->get(['id', 'facility_id', 'name']),
            'shifts' => \App\Models\Shift::whereIn('facility_id', $scope)->where('is_active', true)->orderBy('start_time')
                ->get(['id', 'facility_id', 'name', 'start_time', 'end_time'])
                ->map(fn ($s) => ['id' => $s->id, 'facility_id' => $s->facility_id, 'name' => $s->name.' ('.\App\Models\Shift::label($s->startHm()).' – '.\App\Models\Shift::label($s->endHm()).')']),
            'staff' => $facilityId
                ? User::where('status', User::STATUS_ACTIVE)->where(fn ($q) => $q->whereHas('facilities', fn ($f) => $f->where('facilities.id', $facilityId))
                    ->orWhereHas('roles.permissions', fn ($p) => $p->where('slug', 'facilities.access_all')))->orderBy('name')->get(['id', 'name', 'designation'])
                : [],
        ]]);
    }
}
