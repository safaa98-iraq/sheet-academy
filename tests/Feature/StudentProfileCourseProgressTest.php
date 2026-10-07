<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileCourseProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_courses_are_only_shown_on_profile_with_the_students_own_progress(): void
    {
        $student = Student::factory()->create();
        $other = Student::factory()->create();
        $course = Course::factory()->create(['title' => 'مادة تقدم الطالب']);
        $student->courses()->attach($course);
        $first = Lesson::factory()->for($course)->create(['duration_seconds' => 100]);
        $second = Lesson::factory()->for($course)->create(['duration_seconds' => 100]);
        Lesson::factory()->for($course)->create(['is_published' => false]);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $first->id, 'completed_at' => now(), 'watched_seconds' => 100]);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $second->id, 'watched_seconds' => 50]);
        LessonProgress::create(['student_id' => $other->id, 'lesson_id' => $second->id, 'completed_at' => now(), 'watched_seconds' => 100]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.index'))->assertOk()->assertDontSee('<th>المواد</th>', false)->assertDontSee($course->title);
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee($course->title)->assertSee('75٪')->assertSee('1 من 2 دروس مكتملة');
    }

    public function test_grade_courses_without_progress_show_zero_and_unrelated_courses_are_absent(): void
    {
        $grade = GradeLevel::factory()->create();
        $student = Student::factory()->create(['grade_level_id' => $grade->id]);
        $course = Course::factory()->for($grade)->create();
        Lesson::factory()->for($course)->create();
        $other = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee($course->title)->assertSee('0٪')->assertSee('0 من 1 دروس مكتملة')->assertDontSee($other->title);
    }
}
