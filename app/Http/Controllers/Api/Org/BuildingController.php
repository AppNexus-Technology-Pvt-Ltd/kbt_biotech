<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Building;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BuildingController extends ResourceController
{
    protected function model(): string { return Building::class; }

    protected function noun(): string { return 'building'; }

    protected function with(): array { return ['facility:id,name']; }

    protected function searchable(): array { return ['code', 'name', 'description']; }

    protected function rules(Request $request, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? (int) $request->input('facility_id');

        return [
            'facility_id' => [$record ? 'sometimes' : 'required', Rule::exists('facilities', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('buildings', 'code')->where('facility_id', $facilityId)->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'floors' => ['required', 'integer', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function row(Model $b): array
    {
        return ['id' => $b->id, 'facility_id' => $b->facility_id, 'facility' => $b->facility?->name, 'code' => $b->code, 'name' => $b->name,
            'floors' => $b->floors, 'description' => $b->description, 'is_active' => $b->is_active];
    }

    protected function dependants(Model $b): array
    {
        return ['room(s)/location(s)' => $b->locations()->count()];
    }
}
