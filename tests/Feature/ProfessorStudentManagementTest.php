<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessorStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_professor_can_edit_freeze_reactivate_issue_token_and_delete_student(): void
    {
        $this->freezeTime();
        $professor = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create();
        $old = app(StudentTokenService::class)->issue($student);
        $course = Course::factory()->create();
        $this->actingAs($professor, 'web');
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee('تجميد الحساب')->assertSee('إصدار توكن جديد');
        $this->put(route('admin.students.update', $student), ['name' => 'طالب بعد التعديل', 'email' => 'edited@example.test', 'course_ids' => [$course->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'name' => 'طالب بعد التعديل']);
        $this->assertDatabaseHas('course_student', ['student_id' => $student->id, 'course_id' => $course->id]);
        $this->patch(route('admin.students.status', $student), ['status' => 'frozen'])->assertSessionHasNoErrors();
        $this->assertSame('frozen', $student->fresh()->status);
        $this->assertSame('frozen', $old['record']->fresh()->status);
        $this->get(route('admin.students.show', $student))->assertSee('إعادة تفعيل الحساب');
        $this->post(route('admin.students.token', $student))->assertSessionHasErrors('token');
        $this->assertDatabaseCount('student_tokens', 1);
        $this->patch(route('admin.students.status', $student), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->post(route('admin.students.token', $student), ['device_limit' => 1, 'expires_at' => now()->addMonth()->format('Y-m-d H:i:s')])->assertRedirect(route('admin.students.index'))->assertSessionHas('issued_student_token');
        $token = $student->tokens()->latest('id')->firstOrFail();
        $this->assertSame(1, $token->device_limit);
        $this->assertSame(now()->addMonth()->format('Y-m-d H:i:s'), $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('revoked', $old['record']->fresh()->status);
        $this->delete(route('admin.students.destroy', $student))->assertRedirect(route('admin.students.index'));
        $this->assertModelMissing($student);
    }

    public function test_invalid_token_options_do_not_revoke_the_current_token(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.students.token', $student), ['device_limit' => 11, 'expires_at' => now()->subDay()->toDateTimeString()])->assertSessionHasErrors(['device_limit', 'expires_at']);
        $this->assertSame('active', $issued['record']->fresh()->status);
        $this->assertDatabaseCount('student_tokens', 1);
    }

    public function test_unprivileged_admin_cannot_manage_students(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => false]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => 'تغيير غير مسموح'])->assertForbidden();
        $this->patch(route('admin.students.status', $student), ['status' => 'frozen'])->assertForbidden();
        $this->post(route('admin.students.token', $student))->assertForbidden();
        $this->delete(route('admin.students.destroy', $student))->assertForbidden();
        $this->assertModelExists($student);
        $this->assertSame('active', $student->fresh()->status);
        $this->assertDatabaseCount('student_tokens', 0);
    }
}
