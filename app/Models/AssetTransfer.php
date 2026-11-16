<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTransfer extends Model
{
    public const STATUSES = ['pending' => 'Pending Approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'asset_id', 'from_facility_id', 'from_department_id', 'from_location_id', 'to_facility_id', 'to_department_id', 'to_location_id',
        'requested_date', 'reason', 'remarks', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['requested_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }

    public function fromFacility(): BelongsTo { return $this->belongsTo(Facility::class, 'from_facility_id'); }

    public function fromDepartment(): BelongsTo { return $this->belongsTo(Department::class, 'from_department_id'); }

    public function fromLocation(): BelongsTo { return $this->belongsTo(Location::class, 'from_location_id'); }

    public function toFacility(): BelongsTo { return $this->belongsTo(Facility::class, 'to_facility_id'); }

    public function toDepartment(): BelongsTo { return $this->belongsTo(Department::class, 'to_department_id'); }

    public function toLocation(): BelongsTo { return $this->belongsTo(Location::class, 'to_location_id'); }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
