<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentRestorationService
{
    public function restore(string $type, int $id, Request $request): void
    {
        DB::transaction(function () use ($type, $id, $request): void {
            $model = match ($type) {
                'grade' => GradeLevel::class,
                'course' => Course::class,
                'lesson' => Lesson::class,
            };
            $item = $model::onlyTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $course = $item instanceof Course ? $item : ($item instanceof Lesson ? Course::withTrashed()->whereKey($item->course_id)->lockForUpdate()->first() : null);
            if ($item instanceof Lesson && ($course === null || $course->trashed())) {
                throw ValidationException::withMessages(['restore' => 'استرجع المادة أولاً ثم استرجع المحاضرة.']);
            }
            if ($course?->grade_level_id !== null) {
                $grade = GradeLevel::withTrashed()->whereKey($course->grade_level_id)->lockForUpdate()->first();
                if ($grade === null || $grade->trashed()) {
                    throw ValidationException::withMessages(['restore' => 'استرجع المرحلة الصفية أولاً ثم استرجع المادة والمحاضرة.']);
                }
            }
            $item->restore();
            if ($item instanceof Course) {
                CurriculumNode::onlyTrashed()->where('course_id', $item->id)->whereNull('parent_id')->where('type', 'material')->restore();
            }
            if ($item instanceof Lesson) {
                $node = CurriculumNode::withTrashed()->where('lesson_id', $item->id)->lockForUpdate()->first();
                $visited = [];
                while ($node !== null) {
                    if (isset($visited[$node->id]) || (int) $node->course_id !== (int) $item->course_id) {
                        throw ValidationException::withMessages(['restore' => 'تعذّر استرجاع المحاضرة بسبب ارتباط غير صالح في المنهج.']);
                    }
                    $visited[$node->id] = true;
                    if ($node->trashed()) {
                        $node->restore();
                    }
                    $node = $node->parent_id ? CurriculumNode::withTrashed()->whereKey($node->parent_id)->lockForUpdate()->first() : null;
                }
            }
            AuditLog::create([
                'event' => 'content_restored_by_admin', 'points' => 0, 'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'metadata' => ['actor_id' => $request->user('web')->id, 'kind' => $type, 'content_id' => $item->id, 'name' => $item instanceof GradeLevel ? $item->name : $item->title],
            ]);
            DB::afterCommit(function () use ($course): void {
                if ($course !== null) {
                    Cache::forget('curriculum:course:'.$course->id.':published');
                    Cache::forget('curriculum:course:'.$course->id.':preview');
                }
                Cache::forget('admin.student-progress.stats.v2');
            });
        }, attempts: 3);
    }
}
