<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use ScopedToFacilities;

    public const TYPES = [
        'room' => 'Room', 'ward' => 'Ward', 'operating_theatre' => 'Operating Theatre',
        'laboratory' => 'Laboratory', 'icu' => 'ICU', 'other' => 'Other',
    ];

    protected $fillable = ['facility_id', 'building_id', 'department_id', 'code', 'name', 'type', 'floor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
