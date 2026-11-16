<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    public const TYPES = [
        'main_hospital' => 'Main Hospital',
        'specialty_center' => 'Specialty Center',
        'cancer_center' => 'Cancer Center',
        'diagnostic_center' => 'Diagnostic Center',
        'nursing_home' => 'Nursing Home',
        'clinic' => 'Clinic',
    ];

    protected $fillable = ['name', 'code', 'type', 'city', 'address', 'state', 'country', 'pin_code', 'image_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_primary');
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
