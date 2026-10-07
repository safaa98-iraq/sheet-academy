<?php

namespace App\Policies;

use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;

class LessonPolicy
{
    public function viewByStudent(Student $student, Lesson $lesson): bool
    {
        $course = $lesson->course;
        if ($course === null || ! $lesson->isVisibleToStudents() || ! app(CoursePolicy::class)->viewByStudent($student, $course)) {
            return false;
        }

        $node = CurriculumNode::withTrashed()->where('lesson_id', $lesson->id)->first();
        if ($node === null) {
            return true;
        }

        while ($node !== null) {
            if ($node->trashed() || ! $node->isVisibleToStudents()) {
                return false;
            }
            if ($node->parent_id === null) {
                return $node->type === 'material' && (int) $node->course_id === (int) $lesson->course_id;
            }
            $node = CurriculumNode::withTrashed()->find($node->parent_id);
        }

        return false;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Lesson $lesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Lesson $lesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Lesson $lesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Lesson $lesson): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Lesson $lesson): bool
    {
        return false;
    }
}
