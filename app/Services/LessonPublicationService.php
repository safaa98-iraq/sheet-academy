<?php

namespace App\Services;

use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\Student;
use App\Notifications\NewLecturePublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LessonPublicationService
{
    public function notify(Lesson $lesson): void
    {
        DB::transaction(function () use ($lesson): void {
            $current = Lesson::whereKey($lesson->id)->lockForUpdate()->first();
            if ($current === null || ! $current->notify_students || $current->notified_at !== null || ! $current->isVisibleToStudents()
                || ! $current->course?->isVisibleToStudents() || (($current->type === 'video' || $current->video !== null) && $current->video?->status !== 'ready')) {
                return;
            }
            $node = CurriculumNode::withTrashed()->where('lesson_id', $current->id)->first();
            if ($node !== null) {
                do {
                    if ($node->trashed() || ! $node->isVisibleToStudents()) {
                        return;
                    }
                    if ($node->parent_id === null) {
                        if ($node->type !== 'material' || (int) $node->course_id !== (int) $current->course_id) {
                            return;
                        }
                        break;
                    }
                    $node = CurriculumNode::withTrashed()->find($node->parent_id);
                    if ($node === null) {
                        return;
                    }
                } while (true);
            }
            $course = $current->course;
            Student::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('subscription_expires_at')->orWhere('subscription_expires_at', '>', now()))
                ->whereHas('courses', fn ($query) => $query->whereKey($course->id))->chunkById(100, function ($students) use ($current): void {
                    foreach ($students as $student) {
                        if (Gate::forUser($student)->allows('viewByStudent', $current)) {
                            $student->notify(new NewLecturePublished($current));
                        }
                    }
                });
            $current->forceFill(['notified_at' => now()])->save();
        });
    }
}
