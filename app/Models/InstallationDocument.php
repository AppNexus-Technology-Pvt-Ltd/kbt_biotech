<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationDocument extends Model
{
    protected $fillable = ['installation_job_id', 'category', 'title', 'file_path', 'original_name', 'mime', 'size', 'uploaded_by'];

    public function job(): BelongsTo { return $this->belongsTo(InstallationJob::class, 'installation_job_id'); }

    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
