<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $fillable = ['student_id', 'lesson_id', 'last_position_seconds', 'watched_seconds', 'completed_at', 'last_heartbeat_at', 'is_playing'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'last_heartbeat_at' => 'datetime', 'is_playing' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
