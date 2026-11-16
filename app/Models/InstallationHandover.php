<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationHandover extends Model
{
    protected $fillable = ['installation_job_id', 'handover_date', 'handover_to', 'staff_trained', 'notes', 'signature_path', 'recorded_by'];

    protected function casts(): array
    {
        return ['handover_date' => 'date', 'staff_trained' => 'array'];
    }

    public function job(): BelongsTo { return $this->belongsTo(InstallationJob::class, 'installation_job_id'); }

    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'handover_to'); }

    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
