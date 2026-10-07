<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CurriculumNode extends Model
{
    use SoftDeletes;

    protected $fillable = ['course_id', 'parent_id', 'lesson_id', 'type', 'title', 'position', 'publication_status', 'scheduled_at'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function lessonRecord(): HasOne
    {
        return $this->hasOne(Lesson::class, 'id', 'lesson_id');
    }

    public function scopePublishedForStudents(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('publication_status', 'published')
                ->orWhere(function (Builder $query): void {
                    $query->where('publication_status', 'scheduled')->where('scheduled_at', '<=', now());
                });
        });
    }

    public function isVisibleToStudents(): bool
    {
        return $this->publication_status === 'published'
            || ($this->publication_status === 'scheduled' && $this->scheduled_at?->isPast());
    }
}
