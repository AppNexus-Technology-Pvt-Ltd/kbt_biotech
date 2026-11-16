<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Building;
use App\Models\Department;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends ResourceController
{
    protected function model(): string { return Location::class; }

    protected function noun(): string { return 'location'; }

    protected function with(): array { return ['facility:id,name', 'building:id,name,code', 'department:id,name']; }

    protected function filter(Builder $query, Request $request): void
    {
        if ($request->filled('building_id')) {
            $query->where('building_id', (int) $request->input('building_id'));
        }
        if (array_key_exists($request->input('type'), Location::TYPES)) {
            $query->where('type', $request->input('type'));
        }
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? (int) $request->input('facility_id');

        return [
            'facility_id' => [$record ? 'sometimes' : 'required', Rule::exists('facilities', 'id')->where('is_active', true)],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('locations', 'code')->where('facility_id', $facilityId)->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Location::TYPES))],
            'floor' => ['nullable', 'integer', 'between:-5,200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function crossValidate(array $data, ?Model $record): array
    {
        $facilityId = (int) ($record?->facility_id ?? $data['facility_id'] ?? 0);
        $errors = [];
        if (! empty($data['building_id'])) {
            $building = Building::find($data['building_id']);
            if ($building->facility_id !== $facilityId) {
                $errors['building_id'] = 'The building belongs to a different facility.';
            } elseif (isset($data['floor']) && $data['floor'] > $building->floors - 1) {
                $errors['floor'] = "Floor must be between 0 and ".($building->floors - 1)." for this building (ground floor = 0).";
            }
        }
        if (! empty($data['department_id']) && Department::find($data['department_id'])->facility_id !== $facilityId) {
            $errors['department_id'] = 'The department belongs to a different facility.';
        }

        return $errors;
    }

    protected function row(Model $l): array
    {
        return ['id' => $l->id, 'facility_id' => $l->facility_id, 'facility' => $l->facility?->name, 'building_id' => $l->building_id,
            'building' => $l->building?->name, 'department_id' => $l->department_id, 'department' => $l->department?->name,
            'code' => $l->code, 'name' => $l->name, 'type' => $l->type, 'type_label' => Location::TYPES[$l->type] ?? $l->type,
            'floor' => $l->floor, 'is_active' => $l->is_active];
    }

    protected function dependants(Model $l): array
    {
        return ['asset(s)' => $l->assets()->count()];
    }
}
