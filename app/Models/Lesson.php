<?php

namespace App\Models;

use App\Support\SafeRichText;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['course_id', 'title', 'slug', 'type', 'duration_seconds', 'position', 'is_published', 'description', 'body_html', 'publication_status', 'scheduled_at', 'video_reference', 'video_status', 'available_resolutions'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'scheduled_at' => 'datetime', 'available_resolutions' => 'array'];
    }

    public function scopeVisibleForStudents(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('publication_status', 'published')
                ->orWhere(function (Builder $query): void {
                    $query->where('publication_status', 'scheduled')->where('scheduled_at', '<=', now());
                })
                ->orWhere(fn (Builder $query) => $query->where('publication_status', 'draft')->where('is_published', true));
        });
    }

    public function setBodyHtmlAttribute(?string $value): void
    {
        $this->attributes['body_html'] = SafeRichText::sanitize($value);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function curriculumNode(): HasOne
    {
        return $this->hasOne(CurriculumNode::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    public function video(): HasOne
    {
        return $this->hasOne(LessonVideo::class);
    }

    public function isVisibleToStudents(): bool
    {
        return $this->publication_status === 'published'
            || ($this->publication_status === 'scheduled' && $this->scheduled_at?->isPast())
            || ($this->publication_status === 'draft' && $this->is_published);
    }
}
