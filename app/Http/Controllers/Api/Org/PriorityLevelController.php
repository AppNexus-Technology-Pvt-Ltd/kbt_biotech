<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\PriorityLevel;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Priorities and their SLA targets. New work orders take their deadlines from here (see WorkOrder::booted). */
class PriorityLevelController extends ResourceController
{
    protected function model(): string { return PriorityLevel::class; }

    protected function noun(): string { return 'priority level'; }

    protected function facilityScoped(): bool { return false; }

    protected function searchable(): array { return ['code', 'name']; }

    protected function order(Builder $query): Builder { return $query->orderBy('sort_order')->orderBy('code'); }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            // The code is what work_orders.priority stores, so it is fixed once created.
            'code' => $record ? ['sometimes', Rule::in([$record->code])] : ['required', 'string', 'max:20', 'regex:/^[a-z0-9_]+$/', Rule::unique('priority_levels', 'code')],
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'response_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'resolution_minutes' => ['required', 'integer', 'min:1', 'max:525600', 'gte:response_minutes'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function row(Model $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'color' => $p->color,
            'response_minutes' => $p->response_minutes, 'resolution_minutes' => $p->resolution_minutes,
            'response_label' => PriorityLevel::humanize($p->response_minutes), 'resolution_label' => PriorityLevel::humanize($p->resolution_minutes),
            'is_active' => $p->is_active];
    }

    protected function dependants(Model $p): array
    {
        return ['work order(s)' => WorkOrder::where('priority', $p->code)->count()];
    }
}
