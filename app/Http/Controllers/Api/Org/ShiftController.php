<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends ResourceController
{
    protected function model(): string { return Shift::class; }

    protected function noun(): string { return 'shift'; }

    protected function with(): array { return ['facility:id,name']; }

    protected function searchable(): array { return ['name']; }

    protected function order(Builder $query): Builder { return $query->orderBy('start_time')->orderBy('name'); }

    protected function filter(Builder $query, Request $request): void
    {
        if (array_key_exists($request->input('type'), Shift::TYPES)) {
            $query->where('type', $request->input('type'));
        }
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? (int) $request->input('facility_id');

        return [
            'facility_id' => [$record ? 'sometimes' : 'required', Rule::exists('facilities', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:120', Rule::unique('shifts', 'name')->where('facility_id', $facilityId)->ignore($record?->id)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'type' => ['required', Rule::in(array_keys(Shift::TYPES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function row(Model $s): array
    {
        return ['id' => $s->id, 'facility_id' => $s->facility_id, 'facility' => $s->facility?->name, 'name' => $s->name,
            'start_time' => $s->startHm(), 'end_time' => $s->endHm(),
            'start_label' => Shift::label($s->startHm()), 'end_label' => Shift::label($s->endHm()),
            'type' => $s->type, 'type_label' => Shift::TYPES[$s->type] ?? $s->type, 'is_active' => $s->is_active];
    }

    protected function dependants(Model $s): array
    {
        return ['roster assignment(s)' => $s->assignments()->count()];
    }
}
