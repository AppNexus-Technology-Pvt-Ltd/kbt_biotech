<?php

namespace App\Models;

use App\Models\Concerns\ScopedToFacilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use ScopedToFacilities;

    public const STATUSES = [
        'operational' => 'Operational',
        'under_maintenance' => 'Under Maintenance',
        'out_of_service' => 'Out of Service',
        'retired' => 'Retired',
        'disposed' => 'Disposed',
    ];

    /** Statuses a user may set from the asset form; retire / dispose go through their own workflows. */
    public const EDITABLE_STATUSES = ['operational', 'under_maintenance', 'out_of_service'];

    /** Retired / disposed assets are history: they leave counts and can no longer be edited. */
    public const CLOSED_STATUSES = ['retired', 'disposed'];

    public const TYPES = ['movable' => 'Movable', 'immovable' => 'Immovable'];

    protected $fillable = [
        'facility_id', 'asset_tag', 'name', 'category', 'manufacturer', 'model', 'serial_number',
        'category_id', 'manufacturer_id', 'location_id', 'department_id', 'asset_type', 'status',
        'purchase_date', 'purchase_cost', 'funding_source', 'vendor_name', 'invoice_number', 'specifications',
        'installed_at', 'warranty_expiry_at', 'amc_expiry_at', 'amc_vendor',
        'last_pm_at', 'next_pm_due_at', 'last_calibration_at', 'next_calibration_due_at', 'remarks', 'image_path',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'date',
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'warranty_expiry_at' => 'date',
            'amc_expiry_at' => 'date',
            'last_pm_at' => 'date',
            'next_pm_due_at' => 'date',
            'last_calibration_at' => 'date',
            'next_calibration_due_at' => 'date',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** Count only live equipment (everything not retired / disposed). */
    public function scopeLive($query)
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function categoryRecord(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }

    public function manufacturerRecord(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function installationJobs(): HasMany
    {
        return $this->hasMany(InstallationJob::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
