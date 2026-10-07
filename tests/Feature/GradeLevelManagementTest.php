<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeLevelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_and_delete_an_empty_grade(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.grade-levels.create'))->assertOk();
        $this->post(route('admin.grade-levels.store'), ['name' => 'الصف الأول', 'position' => 1])->assertRedirect(route('admin.grade-levels.index'));
        $grade = GradeLevel::where('name', 'الصف الأول')->firstOrFail();
        $this->get(route('admin.grade-levels.index'))->assertOk()->assertSee('الصف الأول');
        $this->get(route('admin.grade-levels.edit', $grade))->assertOk();
        $this->put(route('admin.grade-levels.update', $grade), ['name' => 'الصف الثاني', 'position' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('grade_levels', ['id' => $grade->id, 'name' => 'الصف الثاني', 'position' => 2]);
        $this->delete(route('admin.grade-levels.destroy', $grade))->assertRedirect(route('admin.grade-levels.index'));
        $this->assertModelMissing($grade);
    }

    public function test_courses_require_an_existing_grade_and_can_move_between_grades(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.courses.create'))->assertRedirect(route('admin.grade-levels.create'));
        $data = ['title' => 'تشريح الأسنان', 'publication_status' => 'draft'];
        $this->post(route('admin.courses.store'), $data)->assertSessionHasErrors(['grade_level_id' => 'اختر المرحلة الصفية للمادة.']);
        $this->post(route('admin.courses.store'), $data + ['grade_level_id' => 999])->assertSessionHasErrors('grade_level_id');
        $grade = GradeLevel::factory()->create();
        $this->get(route('admin.courses.create', ['grade_level_id' => $grade->id]))->assertOk()->assertSee($grade->name);
        $this->post(route('admin.courses.store'), $data + ['grade_level_id' => $grade->id])->assertSessionHasNoErrors();
        $course = Course::where('title', $data['title'])->firstOrFail();
        $this->assertSame($grade->id, $course->grade_level_id);
        $other = GradeLevel::factory()->create();
        $this->put(route('admin.courses.update', $course), $data + ['grade_level_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $course->fresh()->grade_level_id);
        $this->get(route('admin.courses.index', ['grade_level_id' => $grade->id]))->assertDontSee($course->title);
        $this->get(route('admin.courses.index', ['grade_level_id' => $other->id]))->assertSee($course->title);
    }

    public function test_grades_with_courses_including_deleted_courses_cannot_be_deleted(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->delete(route('admin.grade-levels.destroy', $grade))->assertSessionHasErrors('grade_level');
        $course->delete();
        $this->delete(route('admin.grade-levels.destroy', $grade))->assertSessionHasErrors('grade_level');
        $this->assertModelExists($grade);
    }

    public function test_grade_validation_rejects_empty_duplicate_and_invalid_order_values(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.grade-levels.store'), [])->assertSessionHasErrors(['name', 'position']);
        $grade = GradeLevel::factory()->create();
        $this->post(route('admin.grade-levels.store'), ['name' => $grade->name, 'position' => -1])->assertSessionHasErrors(['name', 'position']);
        $this->assertDatabaseCount('grade_levels', 1);
    }

    public function test_grade_management_requires_admin_permissions(): void
    {
        $this->get(route('admin.grade-levels.index'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_super_admin' => false]), 'web');
        $this->get(route('admin.grade-levels.index'))->assertForbidden();
        $this->post(route('admin.grade-levels.store'), ['name' => 'الصف الأول', 'position' => 1])->assertForbidden();
        $this->assertDatabaseCount('grade_levels', 0);
    }

    public function test_student_sees_the_grade_of_only_their_enrolled_courses(): void
    {
        $grade = GradeLevel::factory()->create(['name' => 'الصف الأول']);
        $other = GradeLevel::factory()->create(['name' => 'مرحلة غير مسجل بها']);
        $course = Course::factory()->for($grade)->create();
        Course::factory()->for($other)->create();
        $student = Student::factory()->create();
        $student->forceFill(['content_agreement_accepted_at' => now(), 'content_agreement_version' => config('audit.agreement_version')])->save();
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->post(route('student.login.store'), ['token' => $issued['token']])->assertRedirect(route('student.learning'));
        $this->get(route('student.learning'))->assertOk()->assertSee('الصف الأول')->assertDontSee($other->name);
    }
}
