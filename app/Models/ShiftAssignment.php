<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    use ScopedToFacilities;

    protected $fillable = ['facility_id', 'shift_id', 'user_id', 'shift_date'];

    protected function casts(): array
    {
        return ['shift_date' => 'date'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
