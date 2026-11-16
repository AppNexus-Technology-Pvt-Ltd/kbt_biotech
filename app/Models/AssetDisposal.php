<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDisposal extends Model
{
    public const REASONS = [
        'end_of_useful_life' => 'End of useful life', 'beyond_economical_repair' => 'Beyond economical repair',
        'obsolete_technology' => 'Obsolete technology', 'damaged' => 'Damaged', 'other' => 'Other',
    ];

    public const CONDITIONS = ['working' => 'Working', 'partially_working' => 'Partially Working', 'not_working' => 'Not Working'];

    protected $fillable = ['asset_id', 'type', 'reason', 'condition', 'disposal_date', 'remarks', 'performed_by'];

    protected function casts(): array
    {
        return ['disposal_date' => 'date'];
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
}
