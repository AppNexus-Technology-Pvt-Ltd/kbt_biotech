<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use ScopedToFacilities;

    public const TYPES = ['regular' => 'Regular', 'on_call' => 'On-Call'];

    protected $fillable = ['facility_id', 'name', 'start_time', 'end_time', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /** "H:i" for <input type="time">. */
    public function startHm(): string
    {
        return substr($this->start_time, 0, 5);
    }

    public function endHm(): string
    {
        return substr($this->end_time, 0, 5);
    }

    public static function label(string $hm): string
    {
        return Carbon::createFromFormat('H:i', $hm)->format('h:i A');
    }
}
