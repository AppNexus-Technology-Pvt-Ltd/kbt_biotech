<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationChecklistItem extends Model
{
    public const SECTIONS = ['site_readiness' => 'Site Readiness', 'installation' => 'Installation Checklist', 'acceptance' => 'Acceptance Test'];

    /** Allowed statuses per section; the first is the starting value. */
    public const STATUSES = [
        'site_readiness' => ['pending', 'pass', 'fail', 'na'],
        'installation' => ['pending', 'in_progress', 'completed'],
        'acceptance' => ['pending', 'pass', 'fail'],
    ];

    /** Statuses that count as "done" for each section. */
    public const DONE = [
        'site_readiness' => ['pass', 'na'],
        'installation' => ['completed'],
        'acceptance' => ['pass'],
    ];

    /** Default items copied to every job when it is submitted: [title, detail]. */
    public const TEMPLATES = [
        'site_readiness' => [
            ['Space Availability', 'Ensure required space as per manufacturer'],
            ['Power Supply', 'Verify power points and voltage'],
            ['Earthing', 'Check proper earthing'],
            ['Environmental Condition', 'Temperature and humidity within range'],
            ['Network / Connectivity', 'LAN / Wi-Fi ready (if required)'],
            ['Gas / Utility Lines', 'Oxygen, air, vacuum (if applicable)'],
            ['Safety Clearance', 'No obstruction and safe access'],
        ],
        'installation' => [
            ['Unpack and inspect equipment', 'No physical damage'],
            ['Verify accessories and spares', 'All items received'],
            ['Mechanical assembly', 'As per manual'],
            ['Electrical connections', 'Verified'],
            ['Software installation', 'Software / firmware installed and version recorded'],
            ['Functional verification', 'System boots successfully'],
            ['User demonstration', 'Completed with staff'],
        ],
        'acceptance' => [
            ['Power ON Test', 'System boots within 60 sec'],
            ['Display Check', 'No dead pixels'],
            ['Parameter Accuracy', '±2%'],
            ['Alarm Function', 'All alarms trigger'],
            ['Battery Backup', '≥ 120 min'],
            ['Safety Leakage', '< 100 µA'],
        ],
    ];

    protected $fillable = ['installation_job_id', 'section', 'position', 'title', 'detail', 'measured_value', 'status', 'remarks', 'is_custom', 'updated_by'];

    protected function casts(): array
    {
        return ['is_custom' => 'boolean'];
    }

    public function job(): BelongsTo { return $this->belongsTo(InstallationJob::class, 'installation_job_id'); }
}
