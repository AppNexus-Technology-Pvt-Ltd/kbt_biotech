<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManufacturerController extends ResourceController
{
    protected function model(): string { return Manufacturer::class; }

    protected function noun(): string { return 'manufacturer'; }

    protected function facilityScoped(): bool { return false; }

    protected function searchable(): array { return ['code', 'name', 'country']; }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('manufacturers', 'code')->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'country' => $m->country, 'is_active' => $m->is_active];
    }

    protected function dependants(Model $m): array
    {
        return ['asset(s)' => $m->assets()->count()];
    }
}
