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

    public function test_only_explicit_courses_are_visible_and_future_courses_in_the_same_grade_remain_inaccessible(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.students.store'), ['name' => 'طالب المواد', 'access_type' => 'courses', 'study_grade_level_id' => $grade->id, 'course_ids' => [$course->id]])->assertSessionHasNoErrors();
        $student = Student::where('name', 'طالب المواد')->firstOrFail();
        $future = Course::factory()->for($grade)->create();
        $draft = Course::factory()->for($grade)->create(['published_at' => null, 'publication_status' => 'draft']);
        $student->courses()->attach($draft);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($course->title)->assertDontSee($future->title)->assertDontSee($draft->title);
        $this->get(route('student.course.show', $course))->assertOk();
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk();
        $this->get(route('student.course.show', $future))->assertNotFound();
        $futureLesson = Lesson::factory()->for($future)->create();
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $futureLesson->id])->assertNotFound();
        $this->get(route('student.playlist'))->assertOk()->assertSee($course->title)->assertDontSee($future->title);
    }

    public function test_switching_to_custom_courses_replaces_automatic_grade_access(): void
    {
        $grade = GradeLevel::factory()->create();
        $unselected = Course::factory()->for($grade)->create();
        $first = Course::factory()->create();
        $second = Course::factory()->create();
        $student = Student::factory()->create(['grade_level_id' => $grade->id]);
        $this->assertSame([$unselected->id], $student->accessibleCourses()->pluck('id')->all());
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        foreach ([$first, $second] as $selected) {
            $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'courses', 'course_ids' => [$selected->id], 'grade_level_id' => $grade->id])->assertSessionHasNoErrors();
            $this->assertNull($student->fresh()->grade_level_id);
            $this->assertSame([$selected->id], $student->fresh()->accessibleCourses()->pluck('id')->all());
        }
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee($second->title)->assertDontSee($first->title)->assertDontSee($unselected->title);
    }

    public function test_invalid_grade_is_rejected_and_unprivileged_admin_cannot_change_enrollments(): void
    {
        $student = Student::factory()->create();
        $grade = GradeLevel::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'grade', 'grade_level_id' => 999])->assertSessionHasErrors('grade_level_id');
        $this->actingAs(User::factory()->create(['is_super_admin' => false]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'courses'])->assertForbidden();
        $this->assertNull($student->fresh()->grade_level_id);
        $this->assertSame(0, $student->courses()->count());
    }
}
