<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;

class CoursePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('courses.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Course $course): bool
    {
        return $user->hasPermissionTo('courses.view');
    }

    public function viewByStudent(Student $student, Course $course): bool
    {
        return $student->status === 'active'
            && $course->isVisibleToStudents()
            && $student->accessibleCourses()->whereKey($course->id)->exists();
    }

    public function updateAny(User $user): bool
    {
        return $user->hasPermissionTo('courses.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('courses.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Course $course): bool
    {
        return $user->hasPermissionTo('courses.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Course $course): bool
    {
        return $user->hasPermissionTo('courses.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Course $course): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Course $course): bool
    {
        return false;
    }
}
