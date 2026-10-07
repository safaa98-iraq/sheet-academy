<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['grade_level_id', 'title', 'slug', 'description', 'cover_image', 'published_at', 'created_by', 'position', 'publication_status', 'scheduled_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'scheduled_at' => 'datetime'];
    }

    public function scopeVisibleForStudents(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('publication_status', 'published')
                ->orWhere(function (Builder $query): void {
                    $query->where('publication_status', 'scheduled')->where('scheduled_at', '<=', now());
                })
                ->orWhere(fn (Builder $query) => $query->where('publication_status', 'draft')->whereNotNull('published_at'));
        });
    }

    public function isVisibleToStudents(): bool
    {
        return $this->publication_status === 'published'
            || ($this->publication_status === 'scheduled' && $this->scheduled_at?->isPast())
            || ($this->publication_status === 'draft' && $this->published_at !== null);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withPivot('enrolled_at');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    public function curriculumRoot(): HasOne
    {
        return $this->hasOne(CurriculumNode::class)->whereNull('parent_id')->where('type', 'material');
    }
}
