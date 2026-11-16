<?php

namespace App\Http\Controllers\Api\Org;

use App\Models\Facility;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Facilities are the root of the hierarchy: no facility_id column, and they are deactivated, never deleted.
 * Only holders of facilities.access_all may create one (otherwise a manager could mint a facility they cannot see).
 */
class FacilityController extends ResourceController
{
    protected function model(): string { return Facility::class; }

    protected function noun(): string { return 'facility'; }

    protected function facilityScoped(): bool { return false; }

    protected function searchable(): array { return ['code', 'name', 'city']; }

    protected function facilityIdOf(Model $record): ?int { return $record->id; }

    protected function assertFacilityAllowed(Request $request, int $facilityId): void
    {
        abort_unless($request->user()->canAccessFacility($facilityId), 403, 'You do not have access to this facility.');
    }

    protected function scopeToUser(Builder $query, ?array $ids): Builder
    {
        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    protected function filter(Builder $query, Request $request): void
    {
        if (array_key_exists($request->input('type'), Facility::TYPES)) {
            $query->where('type', $request->input('type'));
        }
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('facilities', 'code')->ignore($record?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Facility::TYPES))],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'pin_code' => ['required', 'string', 'regex:/^[A-Za-z0-9 -]{3,20}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessAllFacilities(), 403, 'Only administrators can create facilities.');
        $data = $this->validated($request, null);
        $data['image_path'] = $this->storeImage($request);
        unset($data['image'], $data['remove_image']);
        $facility = Facility::create($data);
        AuditLogger::log('facility.created', $request->user(), $facility, null, collect($data)->except('image_path')->all());

        return response()->json(['success' => true, 'message' => 'Facility created.', 'data' => $this->row($facility)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $facility = $this->find($request, $id);
        $data = $this->validated($request, $facility);

        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $previous = $facility->image_path;
            $data['image_path'] = $this->storeImage($request);
            if ($previous) {
                Storage::disk('local')->delete($previous);
            }
        }
        unset($data['image'], $data['remove_image']);
        $old = $facility->only(array_keys($data));
        $facility->update($data);
        AuditLogger::log('facility.updated', $request->user(), $facility, $old, $facility->only(array_keys($data)));

        return response()->json(['success' => true, 'message' => 'Facility updated.', 'data' => $this->row($facility->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Facilities cannot be deleted. Deactivate the facility instead.'], 405);
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }
        $file = $request->file('image');

        return $file->storeAs('facility-images', Str::uuid().'.'.$file->guessExtension(), 'local');
    }

    protected function row(Model $f): array
    {
        return ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'type' => $f->type, 'type_label' => Facility::TYPES[$f->type] ?? $f->type,
            'address' => $f->address, 'city' => $f->city, 'state' => $f->state, 'country' => $f->country, 'pin_code' => $f->pin_code,
            'is_active' => $f->is_active, 'image_url' => $f->image_path ? route('org.facilities.image', $f) : null];
    }
}
