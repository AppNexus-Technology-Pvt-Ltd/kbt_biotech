<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The duty / on-call roster: who works which shift on which day. */
class ShiftAssignmentController extends ResourceController
{
    protected function model(): string { return ShiftAssignment::class; }

    protected function noun(): string { return 'roster entry'; }

    protected function with(): array { return ['facility:id,name', 'shift:id,name,type,start_time,end_time', 'user:id,name,designation']; }

    protected function searchable(): array { return []; }

    protected function order(Builder $query): Builder { return $query->orderByDesc('shift_date')->orderBy('shift_id'); }

    protected function filter(Builder $query, Request $request): void
    {
        if ($request->filled('shift_id')) {
            $query->where('shift_id', (int) $request->input('shift_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('shift_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('shift_date', '<=', $request->date('to'));
        }
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $facilityId = $record?->facility_id ?? (int) $request->input('facility_id');

        return [
            'facility_id' => [$record ? 'sometimes' : 'required', Rule::exists('facilities', 'id')->where('is_active', true)],
            'shift_id' => ['required', Rule::exists('shifts', 'id')->where('is_active', true)->where('facility_id', $facilityId)],
            'user_id' => ['required', Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE)],
            'shift_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function crossValidate(array $data, ?Model $record): array
    {
        $facilityId = (int) ($record?->facility_id ?? $data['facility_id'] ?? 0);
        $errors = [];
        if (! User::find($data['user_id'])?->canAccessFacility($facilityId)) {
            $errors['user_id'] = 'The selected person is not assigned to this facility.';
        }
        $duplicate = ShiftAssignment::where('shift_id', $data['shift_id'])->where('user_id', $data['user_id'])
            ->whereDate('shift_date', $data['shift_date'])->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists();
        if ($duplicate) {
            $errors['user_id'] = 'This person is already rostered on that shift and date.';
        }

        return $errors;
    }

    protected function row(Model $a): array
    {
        $shift = $a->shift;

        return ['id' => $a->id, 'facility_id' => $a->facility_id, 'facility' => $a->facility?->name, 'shift_id' => $a->shift_id,
            'user_id' => $a->user_id, 'staff' => $a->user?->name, 'designation' => $a->user?->designation,
            'shift_date' => $a->shift_date->format('Y-m-d'), 'date_label' => $a->shift_date->format('d M Y'),
            'shift' => $shift?->name, 'shift_type' => $shift ? (Shift::TYPES[$shift->type] ?? $shift->type) : null,
            'shift_time' => $shift ? Shift::label($shift->startHm()).' – '.Shift::label($shift->endHm()) : null, 'is_active' => true];
    }
}

