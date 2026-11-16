<?php

namespace App\Services;

use App\Models\User;

class FacilityContext
{
    /** The facility the user explicitly selected (validated against their access every call). */
    public static function selectedId(User $user): ?int
    {
        $id = session('facility_id');

        return $id && $user->canAccessFacility((int) $id) ? (int) $id : null;
    }

    /**
     * Facility ids that data queries must be limited to.
     * Null = unrestricted (holds facilities.access_all and selected "all facilities").
     *
     * @return array<int>|null
     */
    public static function ids(User $user): ?array
    {
        if ($selected = self::selectedId($user)) {
            return [$selected];
        }
        if ($user->canAccessAllFacilities()) {
            return null;
        }

        return $user->facilities()->pluck('facilities.id')->map(fn ($i) => (int) $i)->all();
    }
}
