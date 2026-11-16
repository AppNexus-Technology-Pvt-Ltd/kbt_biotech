<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a facility-owned model to the given facility ids.
 * A null list means "unrestricted" (user holds facilities.access_all and chose "all facilities").
 */
trait ScopedToFacilities
{
    /** @param  array<int>|null  $facilityIds */
    public function scopeInFacilities(Builder $query, ?array $facilityIds): Builder
    {
        return $facilityIds === null ? $query : $query->whereIn($this->getTable().'.facility_id', $facilityIds);
    }
}
