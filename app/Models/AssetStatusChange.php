<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetStatusChange extends Model
{
    protected $fillable = ['asset_id', 'from_status', 'to_status', 'reason', 'effective_date', 'remarks', 'changed_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
