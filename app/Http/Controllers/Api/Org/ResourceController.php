<?php

namespace App\Http\Controllers\Api\Org;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\FacilityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared list / create / update / activate / delete behaviour for the organization masters.
 * Subclasses declare their model, validation, row shape and delete guards. Permission checks are
 * applied by the routes (`organization.view` / `organization.manage`); facility access is enforced here.
 */
abstract class ResourceController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Audit / message noun, e.g. "building". */
    abstract protected function noun(): string;

    /** @return array<string,mixed> */
    abstract protected function rules(Request $request, ?Model $record): array;

    /** @return array<string,mixed> */
    abstract protected function row(Model $record): array;

    /** @return array<int,string> columns matched by the free-text search */
    protected function searchable(): array
    {
        return ['code', 'name'];
    }

    /** @return array<int,string> */
    protected function with(): array
    {
        return [];
    }

    /** Whether rows belong to a facility through `facility_id` (all masters except categories). */
    protected function facilityScoped(): bool
    {
        return true;
    }

    protected function facilityIdOf(Model $record): ?int
    {
        return $this->facilityScoped() ? (int) $record->facility_id : null;
    }

    protected function scopeToUser(Builder $query, ?array $ids): Builder
    {
        return $this->facilityScoped() ? $query->inFacilities($ids) : $query;
    }

    protected function order(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    /** Extra query-string filters (type, building…). */
    protected function filter(Builder $query, Request $request): void {}

    /** @return array<string,int> label => number of dependants; any non-zero count blocks deletion. */
    protected function dependants(Model $record): array
    {
        return [];
    }

    /** Extra cross-field checks after basic validation; return field => message. */
    protected function crossValidate(array $data, ?Model $record): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->model()::query()->with($this->with());
        $this->scopeToUser($query, FacilityContext::ids($user));

        if ($this->facilityScoped() && $request->filled('facility_id')) {
            $query->where('facility_id', (int) $request->input('facility_id'));
        }
        if ($term = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                foreach ($this->searchable() as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }
        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->input('status') === 'active');
        }
        $this->filter($query, $request);

        $page = $this->order($query)->paginate(min(max((int) $request->input('per_page', 10), 5), 50));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($r) => $this->row($r))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->row($this->find($request, $id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertFacilityAllowed($request, (int) $request->input('facility_id'));
        $data = $this->validated($request, null);

        $record = DB::transaction(fn () => $this->model()::create($data));
        AuditLogger::log($this->noun().'.created', $request->user(), $record, null, $record->only(array_keys($data)));

        return response()->json(['success' => true, 'message' => ucfirst($this->noun()).' created.', 'data' => $this->row($record->fresh($this->with()))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = $this->find($request, $id);
        $data = $this->validated($request, $record);
        unset($data['facility_id']); // a record never moves between facilities
        $old = $record->only(array_keys($data));

        DB::transaction(fn () => $record->update($data));
        AuditLogger::log($this->noun().'.updated', $request->user(), $record, $old, $record->only(array_keys($data)));

        return response()->json(['success' => true, 'message' => ucfirst($this->noun()).' updated.', 'data' => $this->row($record->fresh($this->with()))]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);
        $record = $this->find($request, $id);
        $record->update(['is_active' => $request->boolean('is_active')]);
        AuditLogger::log($this->noun().($record->is_active ? '.activated' : '.deactivated'), $request->user(), $record);

        return response()->json(['success' => true, 'message' => ucfirst($this->noun()).($record->is_active ? ' activated.' : ' deactivated.'), 'data' => $this->row($record->fresh($this->with()))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $record = $this->find($request, $id);

        $blocking = collect($this->dependants($record))->filter()->map(fn ($n, $label) => "$n $label")->values();
        if ($blocking->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'This '.$this->noun().' is in use ('.$blocking->implode(', ').'). Deactivate it instead, or remove the linked records first.',
            ], 422);
        }

        $old = $record->toArray();
        $record->delete();
        AuditLogger::log($this->noun().'.deleted', $request->user(), $record, $old);

        return response()->json(['success' => true, 'message' => ucfirst($this->noun()).' deleted.']);
    }

    /* --------------------------------------------------------------- helpers */

    protected function find(Request $request, int $id): Model
    {
        $record = $this->model()::query()->with($this->with())->findOrFail($id);
        if ($facilityId = $this->facilityIdOf($record)) {
            $this->assertFacilityAllowed($request, $facilityId);
        }

        return $record;
    }

    protected function assertFacilityAllowed(Request $request, int $facilityId): void
    {
        if (! $this->facilityScoped()) {
            return;
        }
        abort_unless($facilityId && $request->user()->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
    }

    /** @return array<string,mixed> */
    protected function validated(Request $request, ?Model $record): array
    {
        $data = $request->validate($this->rules($request, $record));
        if ($errors = $this->crossValidate($data, $record)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
