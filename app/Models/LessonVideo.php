<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonVideo extends Model
{
    protected $fillable = ['lesson_id', 'status', 'source_path', 'source_mime', 'source_size', 'source_width', 'source_height', 'duration_seconds', 'output_path', 'key_path', 'key_fingerprint', 'available_resolutions', 'enabled_resolutions', 'job_id', 'error_message', 'processed_at'];

    protected function casts(): array
    {
        return ['available_resolutions' => 'array', 'enabled_resolutions' => 'array', 'processed_at' => 'datetime'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
