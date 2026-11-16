<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    use ScopedToFacilities;

    public const OPEN_STATUSES = ['open', 'in_progress', 'on_hold'];

    protected $fillable = [
        'facility_id', 'asset_id', 'number', 'title', 'type', 'priority', 'status',
        'assigned_to', 'created_by', 'due_at', 'response_due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'response_due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * SLA hook: a new work order gets its response / resolution deadlines from the priority master
     * unless the caller set them explicitly. Later changes to the master do not rewrite existing orders.
     */
    protected static function booted(): void
    {
        static::creating(function (WorkOrder $order) {
            $level = PriorityLevel::where('code', $order->priority)->first();
            if (! $level) {
                return;
            }
            $order->due_at ??= now()->addMinutes($level->resolution_minutes);
            $order->response_due_at ??= now()->addMinutes($level->response_minutes);
        });
    }

    public function isSlaBreached(): bool
    {
        if (! $this->due_at) {
            return false;
        }

        return $this->completed_at ? $this->completed_at->gt($this->due_at) : (in_array($this->status, self::OPEN_STATUSES, true) && $this->due_at->isPast());
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
