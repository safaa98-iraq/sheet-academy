<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentDeletionService
{
    public function kind(GradeLevel|Course|Lesson $content): string
    {
        return match (true) {
            $content instanceof GradeLevel => 'grade', $content instanceof Course => 'course', default => 'lesson'
        };
    }

    public function preview(GradeLevel|Course|Lesson $content): array
    {
        $kind = $this->kind($content);
        $courses = $content instanceof GradeLevel ? Course::withTrashed()->where('grade_level_id', $content->id)->orderBy('id')->pluck('id')->all() : [$content instanceof Course ? $content->id : $content->course_id];
        $lessons = $content instanceof Lesson ? [$content->id] : Lesson::whereIn('course_id', $courses)->orderBy('id')->pluck('id')->all();
        $attachments = LessonAttachment::whereIn('lesson_id', $lessons)->orderBy('id')->pluck('id')->all();
        $students = Student::whereHas('courses', fn ($query) => $query->whereIn('courses.id', $courses))->orderBy('id')->pluck('id')->all();

        return ['kind' => $kind, 'name' => $content instanceof GradeLevel ? $content->name : $content->title, 'courses' => $kind === 'lesson' ? 0 : Course::whereIn('id', $courses)->count(), 'lessons' => count($lessons), 'attachments' => count($attachments), 'students' => count($students),
            'fingerprint' => hash('sha256', json_encode([$kind, $content->id, $content->updated_at?->toDateTimeString(), $courses, $lessons, $attachments, $students], JSON_THROW_ON_ERROR))];
    }

    public function confirmationKey(GradeLevel|Course|Lesson $content): string
    {
        return 'content_deletion.'.$this->kind($content).'.'.$content->id;
    }

    public function delete(Request $request, GradeLevel|Course|Lesson $content): void
    {
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'confirmation' => ['required', 'string', 'size:64']], ['confirmed.accepted' => 'اقرأ العواقب وأكّد فهمها قبل الحذف.', 'confirmed.required' => 'أكّد فهم عواقب الحذف أولاً.', 'confirmation.required' => 'افتح مربع تأكيد الحذف أولاً.']);
        $key = $this->confirmationKey($content);
        $confirmation = $request->session()->get($key);
        DB::transaction(function () use ($request, $content, $data, $confirmation): void {
            $current = $content->newQuery()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $courseIds = $current instanceof GradeLevel ? Course::withTrashed()->where('grade_level_id', $current->id)->pluck('id')->all() : [$current instanceof Course ? $current->id : $current->course_id];
            Course::withTrashed()->whereIn('id', $courseIds)->orderBy('id')->lockForUpdate()->get();
            if (! $current instanceof Lesson) {
                Lesson::whereIn('course_id', $courseIds)->orderBy('id')->lockForUpdate()->get();
            }
            $preview = $this->preview($current);
            if (! is_array($confirmation) || ($confirmation['expires_at'] ?? 0) < now()->timestamp || ! hash_equals($confirmation['nonce'] ?? '', $data['confirmation']) || ! hash_equals($confirmation['fingerprint'] ?? '', $preview['fingerprint'])) {
                throw ValidationException::withMessages(['confirmation' => 'تغيّر المحتوى أو انتهى تأكيد الحذف. افتح مربع الحذف مجدداً وراجع العواقب.']);
            }
            if ($current instanceof Lesson) {
                $current->curriculumNode()->delete();
            } else {
                Lesson::whereIn('course_id', $courseIds)->delete();
                CurriculumNode::whereIn('course_id', $courseIds)->delete();
                if ($current instanceof GradeLevel) {
                    Course::whereIn('id', $courseIds)->delete();
                }
            }
            $current->delete();
            AuditLog::create(['event' => 'content_deleted_by_admin', 'points' => 0, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000), 'metadata' => ['actor_id' => $request->user('web')->id, 'content_id' => $current->id, ...array_diff_key($preview, ['fingerprint' => true])]]);
            DB::afterCommit(function () use ($courseIds): void {
                foreach ($courseIds as $id) {
                    Cache::forget('curriculum:course:'.$id.':published');
                    Cache::forget('curriculum:course:'.$id.':preview');
                }
            });
        });
        $request->session()->forget($key);
    }
}
