<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPreference extends Model
{
    protected $fillable = ['student_id', 'video_quality', 'playback_speed', 'is_muted'];

    protected function casts(): array
    {
        return ['playback_speed' => 'float', 'is_muted' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
