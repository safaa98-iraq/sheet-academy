<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_hashed_student_token_starts_a_student_session(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);

        $this->post('/login', ['token' => $issued['token']])->assertRedirect(route('student.agreement.show'));
        $this->assertAuthenticatedAs($student, 'student');

        $this->get('/learning')->assertRedirect(route('student.agreement.show'));
        $this->post(route('student.agreement.accept'), ['accepted' => true])->assertRedirect(route('student.learning'));
        $this->get('/learning')->assertOk()->assertSee($student->name);
        $this->assertSame(hash('sha256', $issued['token']), $issued['record']->fresh()->token_hash);
        $this->assertNotSame($issued['token'], $issued['record']->fresh()->token_hash);
    }

    public function test_invalid_and_expired_tokens_use_the_same_error_message(): void
    {
        $student = Student::factory()->create();
        $expired = app(StudentTokenService::class)->issue($student, null, now()->subMinute());

        $this->from('/login')->post('/login', ['token' => str_repeat('0', 64)])
            ->assertSessionHasErrors(['token' => 'رمز الدخول غير صالح أو منتهي.']);
        $this->from('/login')->post('/login', ['token' => $expired['token']])
            ->assertSessionHasErrors(['token' => 'رمز الدخول غير صالح أو منتهي.']);
    }

    public function test_reissuing_a_student_token_revokes_the_previous_token(): void
    {
        $student = Student::factory()->create();
        $service = app(StudentTokenService::class);
        $old = $service->issue($student);
        $new = $service->issue($student);

        $this->assertSame('revoked', $old['record']->fresh()->status);
        $this->assertSame('active', $new['record']->fresh()->status);
    }

    public function test_public_registration_route_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_token_login_is_limited_per_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['token' => str_repeat('a', 64)])->assertRedirect();
        }

        $this->post('/login', ['token' => str_repeat('a', 64)])
            ->assertRedirect(route('student.login'))
            ->assertSessionHasErrors(['token' => 'رمز الدخول غير صالح أو منتهي.']);
    }

    public function test_token_issued_from_admin_creation_logs_the_student_in(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'web')->post(route('admin.students.store'), ['name' => 'طالب الدخول الفعلي'])
            ->assertRedirect(route('admin.students.index'));
        $token = session('issued_student_token.token');
        $student = Student::where('name', 'طالب الدخول الفعلي')->firstOrFail();
        $this->post(route('student.login.store'), ['token' => '  '.$token.'  '])->assertRedirect(route('student.agreement.show'));
        $this->assertAuthenticatedAs($student, 'student');
    }

    public function test_preview_token_explains_how_to_get_a_real_token(): void
    {
        $this->post(route('student.login.store'), ['token' => 'SH-XBQH8S4F'])
            ->assertSessionHasErrors(['token' => 'هذا رمز معاينة تجريبي. اطلب رمز دخول فعلياً من إدارة المنصة.']);
        $this->assertGuest('student');
        $this->get('/preview/students')->assertNotFound();
        $this->get('/preview/learning')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('/preview/');
    }

    public function test_reactivating_a_suspended_student_restores_the_current_token_only(): void
    {
        $student = Student::factory()->create();
        $service = app(StudentTokenService::class);
        $old = $service->issue($student);
        $current = $service->issue($student);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->patch(route('admin.students.status', $student), ['status' => 'suspended'])->assertSessionHasNoErrors();
        $this->post('/login', ['token' => $current['token']])->assertSessionHasErrors('token');
        $this->assertGuest('student');
        $this->patch(route('admin.students.status', $student), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $old['record']->fresh()->status);
        $this->post('/login', ['token' => $current['token']])->assertRedirect(route('student.agreement.show'));
        $this->assertAuthenticatedAs($student, 'student');
    }
}
