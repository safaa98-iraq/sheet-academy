<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Student extends Authenticatable
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'status', 'created_by', 'grade_level_id'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(StudentToken::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(StudentDevice::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)->withPivot('enrolled_at');
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function accessibleCourses(): Builder
    {
        return Course::query()->when($this->grade_level_id !== null,
            fn (Builder $query) => $query->where('grade_level_id', $this->grade_level_id),
            fn (Builder $query) => $query->whereHas('students', fn (Builder $students) => $students->whereKey($this->id)),
        );
    }

    public function preference(): HasOne
    {
        return $this->hasOne(StudentPreference::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(StudentPlaylist::class);
    }
}
