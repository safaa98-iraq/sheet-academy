<?php

namespace App\Models;

use Database\Factories\StudentPlaylistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentPlaylist extends Model
{
    /** @use HasFactory<StudentPlaylistFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'name'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudentPlaylistItem::class)->orderBy('position')->orderBy('id');
    }
}
