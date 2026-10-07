<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentToken;
use App\Models\User;
use App\Services\StudentTokenService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionsAndRolesSeeder::class);
    }

    public function test_unauthenticated_requests_cannot_access_administration(): void
    {
        $this->get('/admin/students')->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_without_permission_is_forbidden(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'web')->get('/admin/students')->assertForbidden();
    }

    public function test_super_admin_can_create_a_student_and_token_is_only_shown_once(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create();
        $this->actingAs($admin, 'web');

        $this->post('/admin/students', ['name' => 'طالب تجريبي', 'course_ids' => [$course->id]])
            ->assertRedirect(route('admin.students.index'))
            ->assertSessionHas('issued_student_token');

        $student = Student::where('name', 'طالب تجريبي')->firstOrFail();
        $this->assertDatabaseHas('course_student', ['student_id' => $student->id, 'course_id' => $course->id]);
        $token = StudentToken::where('student_id', $student->id)->firstOrFail();
        $plainTextToken = session('issued_student_token.token');
        $this->assertSame(hash('sha256', $plainTextToken), $token->token_hash);

        $this->get('/admin/students')->assertOk()->assertSee($plainTextToken);
        $this->get('/admin/students')->assertOk()->assertDontSee($plainTextToken);
    }

    public function test_frozen_student_session_is_closed_on_next_request(): void
    {
        $student = Student::factory()->create();
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $student->update(['status' => 'frozen']);
        $token['record']->update(['status' => 'frozen']);

        $this->get('/learning')->assertRedirect(route('student.login'));
    }

    public function test_super_admin_can_create_an_admin_with_a_role(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $role = Role::where('name', 'student-manager')->firstOrFail();
        $this->actingAs($admin, 'web')->post('/admin/admins', [
            'name' => 'مسؤول الطلاب',
            'email' => 'manager@example.test',
            'password' => 'A-Long-Secret-Password-123',
            'password_confirmation' => 'A-Long-Secret-Password-123',
            'role_ids' => [$role->id],
        ])->assertRedirect(route('admin.admins.index'));

        $created = User::where('email', 'manager@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('A-Long-Secret-Password-123', $created->password));
        $this->assertTrue($created->roles->contains($role));
    }

    public function test_admin_management_cannot_assign_the_protected_system_role(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $systemRole = Role::where('name', 'super-admin')->firstOrFail();
        $this->actingAs($admin, 'web');

        $this->from('/admin/admins/create')->post('/admin/admins', [
            'name' => 'مسؤول غير مصرح',
            'email' => 'escalation@example.test',
            'password' => 'A-Long-Secret-Password-123',
            'password_confirmation' => 'A-Long-Secret-Password-123',
            'role_ids' => [$systemRole->id],
        ])->assertSessionHasErrors('role_ids.0');

        $ordinaryAdmin = User::factory()->create();
        $this->from('/admin/admins/'.$ordinaryAdmin->id.'/edit')->put('/admin/admins/'.$ordinaryAdmin->id, [
            'name' => $ordinaryAdmin->name,
            'email' => $ordinaryAdmin->email,
            'role_ids' => [$systemRole->id],
            'is_active' => true,
        ])->assertSessionHasErrors('role_ids.0');

        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
        $this->assertFalse($ordinaryAdmin->fresh()->roles->contains($systemRole));
    }

    public function test_admin_login_is_rate_limited_by_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
                ->assertRedirect();
        }

        $this->post('/admin/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
    }

    public function test_admin_can_sign_in_with_email_and_password(): void
    {
        $admin = User::factory()->create(['email' => 'professor@example.test', 'password' => Hash::make('A-Long-Secret-Password-123')]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'A-Long-Secret-Password-123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_super_admin_can_create_a_course_and_lesson(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'web');

        $this->get('/admin')->assertOk()->assertSee('مرحباً');
        $this->get('/admin/roles')->assertOk()->assertSee('الأدوار والصلاحيات');
        $this->post('/admin/courses', ['grade_level_id' => GradeLevel::factory()->create()->id, 'title' => 'تشريح الأسنان', 'description' => 'مادة تجريبية', 'is_published' => '1'])
            ->assertRedirect();

        $course = Course::where('title', 'تشريح الأسنان')->firstOrFail();
        $this->post("/admin/courses/{$course->id}/lessons", ['title' => 'مقدمة', 'type' => 'video', 'duration_seconds' => 600, 'is_published' => '1'])
            ->assertRedirect(route('admin.courses.edit', $course));
        $this->assertDatabaseHas('lessons', ['course_id' => $course->id, 'title' => 'مقدمة', 'is_published' => true]);
    }
}
