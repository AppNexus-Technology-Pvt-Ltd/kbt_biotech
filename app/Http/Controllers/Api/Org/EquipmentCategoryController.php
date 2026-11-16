<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\EquipmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentCategoryController extends ResourceController
{
    protected function model(): string { return EquipmentCategory::class; }

    protected function noun(): string { return 'category'; }

    protected function facilityScoped(): bool { return false; }

    protected function searchable(): array { return ['code', 'name', 'description']; }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('equipment_categories', 'code')->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function row(Model $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description, 'is_active' => $c->is_active];
    }

    protected function dependants(Model $c): array
    {
        return ['asset(s)' => $c->assets()->count()];
    }
}
