<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use ScopedToFacilities;

    protected $fillable = ['facility_id', 'code', 'name', 'floors', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'floors' => 'integer'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
