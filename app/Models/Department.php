<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use ScopedToFacilities;

    protected $fillable = ['facility_id', 'code', 'name', 'type', 'hod_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function hod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_user_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
