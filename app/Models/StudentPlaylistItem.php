<?php

namespace App\Models;

use Database\Factories\StudentPlaylistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPlaylistItem extends Model
{
    /** @use HasFactory<StudentPlaylistItemFactory> */
    use HasFactory;

    protected $fillable = ['student_playlist_id', 'lesson_id', 'position'];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(StudentPlaylist::class, 'student_playlist_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
