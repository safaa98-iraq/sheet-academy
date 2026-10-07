<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_grade_access_includes_future_courses_and_excludes_other_grades_and_drafts(): void
    {
        $grade = GradeLevel::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.students.store'), ['name' => 'طالب المرحلة', 'access_type' => 'grade', 'grade_level_id' => $grade->id])->assertSessionHasNoErrors();
        $student = Student::where('name', 'طالب المرحلة')->firstOrFail();
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        $other = Course::factory()->for(GradeLevel::factory())->create();
        $draft = Course::factory()->for($grade)->create(['published_at' => null, 'publication_status' => 'draft']);
        $this->assertSame(0, $student->courses()->count());
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($course->title)->assertDontSee($other->title)->assertDontSee($draft->title);
        $this->get(route('student.course.show', $course))->assertOk();
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk();
        $this->get(route('student.course.show', $other))->assertNotFound();
        $this->get(route('student.playlist'))->assertOk()->assertSee($course->title);
        $this->get(route('admin.student-progress', ['course_id' => $course->id]))->assertOk()->assertSee($student->name);
    }

    public function test_switching_between_grade_and_specific_courses_replaces_previous_access(): void
    {
        $grade = GradeLevel::factory()->create();
        $gradeCourse = Course::factory()->for($grade)->create();
        $selected = Course::factory()->create();
        $student = Student::factory()->create(['grade_level_id' => $grade->id]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'courses', 'course_ids' => [$selected->id], 'grade_level_id' => $grade->id])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->grade_level_id);
        $this->assertSame([$selected->id], $student->fresh()->accessibleCourses()->pluck('id')->all());
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'grade', 'grade_level_id' => $grade->id, 'course_ids' => [$selected->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, $student->courses()->count());
        $this->assertSame([$gradeCourse->id], $student->fresh()->accessibleCourses()->pluck('id')->all());
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee($grade->name);
        $this->get(route('admin.students.edit', $student))->assertOk()->assertSee('جميع مواد ودروس مرحلة دراسية');
    }

    public function test_grade_selection_is_validated_and_unprivileged_admin_cannot_change_access(): void
    {
        $student = Student::factory()->create();
        $grade = GradeLevel::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'grade'])->assertSessionHasErrors('grade_level_id');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'grade', 'grade_level_id' => 999])->assertSessionHasErrors('grade_level_id');
        $this->actingAs(User::factory()->create(['is_super_admin' => false]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'grade', 'grade_level_id' => $grade->id])->assertForbidden();
        $this->assertNull($student->fresh()->grade_level_id);
    }
}
