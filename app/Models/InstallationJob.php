<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InstallationJob extends Model
{
    use ScopedToFacilities;

    public const STATUSES = [
        'draft' => 'Draft', 'in_progress' => 'In Progress', 'pending_approval' => 'Pending Approval',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    /** A job in one of these states blocks a second job for the same asset. */
    public const OPEN_STATUSES = ['draft', 'in_progress', 'pending_approval'];

    public const TYPES = ['new_installation' => 'New Installation', 'relocation' => 'Relocation', 'recommissioning' => 'Recommissioning'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];

    protected $fillable = [
        'job_number', 'facility_id', 'asset_id', 'department_id', 'type', 'priority', 'vendor_name', 'planned_date', 'estimated_hours',
        'engineer_id', 'remarks', 'status', 'created_by', 'submitted_at', 'approval_requested_by', 'approval_requested_at',
        'decided_by', 'decided_at', 'decision_note', 'completed_at', 'certificate_number',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date', 'estimated_hours' => 'decimal:1', 'submitted_at' => 'datetime', 'approval_requested_at' => 'datetime',
            'decided_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function facility(): BelongsTo { return $this->belongsTo(Facility::class); }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }

    public function engineer(): BelongsTo { return $this->belongsTo(User::class, 'engineer_id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function items(): HasMany { return $this->hasMany(InstallationChecklistItem::class)->orderBy('section')->orderBy('position')->orderBy('id'); }

    public function documents(): HasMany { return $this->hasMany(InstallationDocument::class); }

    public function handover(): HasOne { return $this->hasOne(InstallationHandover::class); }
}
