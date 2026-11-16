<?php

namespace App\Http\Controllers\Api\Org;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FacilityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff Directory: a facility-scoped view over `users`. Accounts, passwords and roles are managed in
 * user administration; here an organization manager only maps a person to a department and designation.
 */
class StaffController extends Controller
{
    private function scoped(Request $request)
    {
        $ids = FacilityContext::ids($request->user());
        $query = User::query()->with(['roles:id,name', 'departmentRecord:id,name', 'facilities:id,name']);
        if ($ids !== null) {
            $query->whereHas('facilities', fn ($f) => $f->whereIn('facilities.id', $ids));
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request);

        if ($request->filled('facility_id')) {
            abort_unless($request->user()->canAccessFacility($request->integer('facility_id')), 403, 'You do not have access to this facility.');
            $query->whereHas('facilities', fn ($f) => $f->where('facilities.id', $request->integer('facility_id')));
        }
        if ($term = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_id', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('slug', $request->input('role')));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $request->input('status') === 'active' ? $query->where('status', User::STATUS_ACTIVE) : $query->where('status', '!=', User::STATUS_ACTIVE);
        }

        $page = $query->orderBy('employee_id')->orderBy('name')->paginate(min(max($request->integer('per_page', 10), 5), 50));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($u) => $this->row($u))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->scoped($request)->findOrFail($id); // outside the caller's facilities => 404
        $data = $request->validate([
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('is_active', true)],
            'designation' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($data['department_id'])) {
            $facilityId = Department::find($data['department_id'])->facility_id;
            if (! $user->canAccessFacility($facilityId)) {
                $message = 'The department belongs to a facility this person is not assigned to.';

                return response()->json(['success' => false, 'message' => $message, 'errors' => ['department_id' => [$message]]], 422);
            }
        }

        $old = $user->only(['department_id', 'designation']);
        $user->forceFill(['department_id' => $data['department_id'] ?? null, 'designation' => $data['designation'] ?? null])->save();
        AuditLogger::log('staff.updated', $request->user(), $user, $old, $user->only(['department_id', 'designation']));

        return response()->json(['success' => true, 'message' => 'Staff member updated.', 'data' => $this->row($user->fresh(['roles', 'departmentRecord', 'facilities']))]);
    }

    private function row(User $u): array
    {
        return ['id' => $u->id, 'facility_id' => $u->facilities->first()?->id, 'code' => $u->employee_id, 'name' => $u->name,
            'email' => $u->email, 'role' => $u->roles->pluck('name')->implode(', ') ?: null,
            'department_id' => $u->department_id, 'department' => $u->departmentRecord?->name ?? $u->department,
            'designation' => $u->designation, 'is_active' => $u->status === User::STATUS_ACTIVE];
    }
}
