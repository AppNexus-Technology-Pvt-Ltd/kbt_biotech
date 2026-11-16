<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends ResourceController
{
    protected function model(): string { return Department::class; }

    protected function noun(): string { return 'department'; }

    protected function with(): array { return ['facility:id,name', 'hod:id,name']; }

    protected function filter(Builder $query, Request $request): void
    {
        if (in_array($request->input('type'), ['clinical', 'non_clinical'], true)) {
            $query->where('type', $request->input('type'));
        }
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? (int) $request->input('facility_id');

        return [
            'facility_id' => [$record ? 'sometimes' : 'required', Rule::exists('facilities', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('departments', 'code')->where('facility_id', $facilityId)->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['clinical', 'non_clinical'])],
            'hod_user_id' => ['nullable', Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function crossValidate(array $data, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? ($data['facility_id'] ?? null);
        if (! empty($data['hod_user_id']) && $facilityId) {
            $hod = User::find($data['hod_user_id']);
            if (! $hod?->canAccessFacility((int) $facilityId)) {
                return ['hod_user_id' => 'The selected person is not assigned to this facility.'];
            }
        }

        return [];
    }

    protected function row(Model $d): array
    {
        return ['id' => $d->id, 'facility_id' => $d->facility_id, 'facility' => $d->facility?->name, 'code' => $d->code, 'name' => $d->name,
            'type' => $d->type, 'hod_user_id' => $d->hod_user_id, 'hod' => $d->hod?->name, 'is_active' => $d->is_active];
    }

    protected function dependants(Model $d): array
    {
        return ['room(s)/location(s)' => $d->locations()->count()];
    }
}
