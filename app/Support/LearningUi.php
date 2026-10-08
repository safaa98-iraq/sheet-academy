<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Student;
use App\Services\StudentAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class LearningUi
{
    /** @return array<string, mixed> */
    public static function course(Course $course, ?Student $student = null, bool $preview = false): array
    {
        $course->loadMissing('gradeLevel', 'lessons.progress', 'lessons.attachments', 'lessons.video');
        $student?->loadMissing('preference');
        $cacheKey = 'curriculum:course:'.$course->id.($preview ? ':preview' : ':published');
        $rows = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($course): array {
            return CurriculumNode::query()->where('course_id', $course->id)
                ->orderBy('position')->orderBy('id')->get(['id', 'type', 'title', 'parent_id', 'lesson_id', 'position', 'publication_status', 'scheduled_at'])
                ->map(fn (CurriculumNode $node): array => $node->only(['id', 'type', 'title', 'parent_id', 'lesson_id', 'position', 'publication_status', 'scheduled_at']))
                ->all();
        });
        if (! $preview) {
            $rows = array_values(array_filter($rows, fn (array $node): bool => $node['publication_status'] === 'published'
                || ($node['publication_status'] === 'scheduled' && $node['scheduled_at'] !== null && Carbon::parse($node['scheduled_at'])->isPast())));
        }

        $lessonModels = $course->lessons->keyBy('id');
        $visibleLessons = $lessonModels->filter(fn ($lesson): bool => $preview || $lesson->isVisibleToStudents());
        $nodes = collect($rows)->filter(function (array $node) use ($visibleLessons): bool {
            if ($node['type'] !== 'lesson') {
                return true;
            }

            return $visibleLessons->has($node['lesson_id']);
        })->keyBy(fn (array $node): string => (string) $node['id'])->all();
        $root = collect($nodes)->first(fn (array $node): bool => $node['type'] === 'material' && $node['parent_id'] === null);
        if ($root === null) {
            $rootId = 'virtual-material-'.$course->id;
            $nodes[$rootId] = ['id' => $rootId, 'type' => 'material', 'title' => $course->title, 'parent_id' => null, 'lesson_id' => null, 'position' => 0, 'publication_status' => $course->published_at ? 'published' : 'draft'];
            $root = $nodes[$rootId];
        }

        $attachedLessonIds = collect($nodes)->filter(fn (array $node): bool => $node['type'] === 'lesson')->pluck('lesson_id')->all();
        $missingLessons = $visibleLessons->reject(fn ($lesson): bool => in_array($lesson->id, $attachedLessonIds, true));
        if ($missingLessons->isNotEmpty()) {
            $section = collect($nodes)->first(fn (array $node): bool => $node['type'] === 'section');
            if ($section === null) {
                $partId = 'virtual-part-'.$course->id;
                $chapterId = 'virtual-chapter-'.$course->id;
                $sectionId = 'virtual-section-'.$course->id;
                $nodes[$partId] = ['id' => $partId, 'type' => 'part', 'title' => 'الجزء الأول', 'parent_id' => $root['id'], 'lesson_id' => null, 'position' => 0, 'publication_status' => 'published'];
                $nodes[$chapterId] = ['id' => $chapterId, 'type' => 'chapter', 'title' => 'الفصل الأول', 'parent_id' => $partId, 'lesson_id' => null, 'position' => 0, 'publication_status' => 'published'];
                $nodes[$sectionId] = ['id' => $sectionId, 'type' => 'section', 'title' => 'القسم الأول', 'parent_id' => $chapterId, 'lesson_id' => null, 'position' => 0, 'publication_status' => 'published'];
                $section = $nodes[$sectionId];
            }
            foreach ($missingLessons as $lesson) {
                $virtualId = 'virtual-lesson-'.$lesson->id;
                $nodes[$virtualId] = ['id' => $virtualId, 'type' => 'lesson', 'title' => $lesson->title, 'parent_id' => $section['id'], 'lesson_id' => $lesson->id, 'position' => $lesson->position, 'publication_status' => $lesson->publication_status];
            }
        }

        $children = [];
        foreach ($nodes as $node) {
            if ($node['parent_id'] !== null) {
                $children[(string) $node['parent_id']][] = $node;
            }
        }
        foreach ($children as &$siblings) {
            usort($siblings, fn (array $left, array $right): int => [$left['position'], $left['id']] <=> [$right['position'], $right['id']]);
        }
        unset($siblings);

        $lessons = [];
        $buildChildren = function (string $parentId, ?string $sectionTitle = null) use (&$buildChildren, &$lessons, $children, $lessonModels, $course, $preview, $student): array {
            $result = [];
            foreach ($children[$parentId] ?? [] as $node) {
                $currentSectionTitle = $node['type'] === 'section' ? $node['title'] : $sectionTitle;
                $item = ['id' => (string) $node['id'], 'type' => $node['type'], 'title' => $node['title'], 'position' => $node['position'], 'children' => []];
                if ($node['type'] === 'lesson') {
                    $model = $lessonModels->get($node['lesson_id']);
                    if ($model === null) {
                        continue;
                    }
                    $lessonId = (string) $model->id;
                    $progress = $model->progress->first();
                    $duration = max(1, (int) $model->duration_seconds);
                    $watchedSeconds = (int) ($progress?->watched_seconds ?? 0);
                    $url = $preview ? route('admin.courses.preview', ['course' => $course, 'lesson' => $model->id]) : route('student.lesson.show', $model);
                    $lesson = [
                        'id' => $lessonId, 'title' => $model->title, 'duration' => $model->duration_seconds,
                        'type' => $model->type, 'position' => $model->position, 'chapter' => $currentSectionTitle ?? 'محتوى المادة',
                        'stream_url' => $model->video?->status === 'ready' ? ($preview
                            ? route('admin.videos.preview-session', $model->video)
                            : route('student.video.session', ['video' => $model->video, 'view' => request()->query('view')])) : null,
                        'video_status_url' => $preview ? route('admin.lessons.video-status', $model) : route('student.lessons.video-status', ['lesson' => $model, 'view' => request()->query('view')]),
                        'video_status' => $model->video?->status ?? $model->video_status,
                        'available_resolutions' => $model->video?->available_resolutions ?? [],
                        'watermark' => $student !== null ? app(StudentAuditService::class)->watermarkText($student) : '',
                        'preferred_quality' => $student?->preference?->video_quality ?? 'auto',
                        'preferred_speed' => $student?->preference?->playback_speed ?? 1,
                        'preferred_muted' => $student?->preference?->is_muted ?? false,
                        'completed' => $progress?->completed_at !== null,
                        'position_seconds' => (int) ($progress?->last_position_seconds ?? 0),
                        'watched_seconds' => $watchedSeconds,
                        'progress_percent' => $progress?->completed_at !== null ? 100 : min(99, (int) round($watchedSeconds / $duration * 100)),
                        'progress_updated_at' => $progress?->updated_at?->toIso8601String(),
                        'description' => $model->description, 'body_html' => $model->body_html,
                        'attachments' => $model->attachments->map(fn ($attachment): array => [
                            'name' => $attachment->original_name,
                            'url' => $preview ? route('admin.lesson-attachments.show', $attachment) : route('student.documents.show', $attachment),
                            'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes,
                        ])->all(),
                        'url' => $url,
                    ];
                    $item['lesson'] = $lesson;
                    $lessons[] = $lesson;
                } else {
                    $item['children'] = $buildChildren((string) $node['id'], $currentSectionTitle);
                }
                $result[] = $item;
            }

            return $result;
        };
        $curriculum = $buildChildren((string) $root['id']);
        $addProgressPercentages = function (array &$items) use (&$addProgressPercentages): array {
            $completed = 0;
            $total = 0;
            foreach ($items as &$item) {
                if ($item['type'] === 'lesson' && isset($item['lesson'])) {
                    $total++;
                    $completed += $item['lesson']['completed'] ? 1 : 0;
                    $item['progress_percent'] = $item['lesson']['progress_percent'];

                    continue;
                }
                [$childCompleted, $childTotal] = $addProgressPercentages($item['children']);
                $completed += $childCompleted;
                $total += $childTotal;
                $item['progress_percent'] = $childTotal > 0 ? (int) round($childCompleted / $childTotal * 100) : 0;
            }
            unset($item);

            return [$completed, $total];
        };
        $addProgressPercentages($curriculum);
        $flatLessons = $lessons;
        $courseUrl = $preview ? route('admin.courses.preview', $course) : route('student.course.show', $course);

        return [
            'grade_level_id' => $course->grade_level_id, 'grade_level_name' => $course->gradeLevel?->name,
            'id' => (string) $course->id, 'title' => $course->title, 'english' => 'DENTAL EDUCATION',
            'description' => $course->description, 'cover' => ['anatomy', 'dental', 'pharma', 'xray'][$course->id % 4],
            'cover_url' => $course->cover_image, 'teacher' => 'فريق الأكاديمية', 'lessons' => $flatLessons,
            'curriculum' => $curriculum,
            'percentage' => count($flatLessons) ? (int) round(array_sum(array_map(fn (array $lesson): int => $lesson['progress_percent'], $flatLessons)) / count($flatLessons)) : 0,
            'url' => $courseUrl,
        ];
    }

    /** @param array<int, array<string, mixed>> $courseCards
     * @return array<string, array<string, mixed>>
     */
    public static function progressSnapshot(array $courseCards): array
    {
        $snapshot = [];
        foreach ($courseCards as $course) {
            foreach ($course['lessons'] as $lesson) {
                $snapshot[(string) $lesson['id']] = [
                    'position' => (int) ($lesson['position_seconds'] ?? 0),
                    'watched' => (int) ($lesson['watched_seconds'] ?? 0),
                    'completed' => (bool) ($lesson['completed'] ?? false),
                    'duration' => (int) ($lesson['duration'] ?? 0),
                    'updatedAt' => $lesson['progress_updated_at'] ?? null,
                ];
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array<string, mixed>
     */
    public static function lesson(array $course, string $lessonId, bool $preview = false): array
    {
        $lessons = $course['lessons'];
        $index = array_search($lessonId, array_column($lessons, 'id'), true);
        abort_if($index === false, 404);

        return array_merge(['description' => null, 'body_html' => '', 'attachments' => []], $lessons[$index], ['course_id' => $course['id'], 'course_title' => $course['title'], 'course_url' => $course['url'], 'viewer_url' => $preview ? route('admin.courses.preview', ['course' => $course['id'], 'lesson' => $lessonId]) : route('student.viewer'), 'previous_url' => $lessons[$index - 1]['url'] ?? null, 'next_url' => $lessons[$index + 1]['url'] ?? null]);
    }
}
