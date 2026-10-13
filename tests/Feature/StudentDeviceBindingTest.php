<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use App\Services\StudentDeviceBindingService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_binds_the_browser_and_returns_a_protected_persistent_cookie(): void
    {
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, '');
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $response = $this->post('/login', ['token' => $issued['token']])->assertRedirect(route('student.agreement.show'));
        $cookie = $response->getCookie(StudentDeviceBindingService::COOKIE_NAME);

        $this->assertNotNull($student->fresh()->bound_device_at);
        $this->assertSame(hash('sha256', $cookie->getValue()), $student->fresh()->bound_device_hash);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(now()->addYear()->timestamp, $cookie->getExpiresTime());
        $this->assertArrayNotHasKey('bound_device_hash', $student->fresh()->toArray());
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'device_bound']);
    }

    public function test_logout_keeps_the_binding_and_the_same_browser_can_return(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $hash = $student->fresh()->bound_device_hash;
        $this->post(route('student.logout'))->assertRedirect(route('student.login'));
        $this->assertSame($hash, $student->fresh()->bound_device_hash);

        $this->loginStudent($issued['token'])->assertRedirect(route('student.learning'));
        $this->get(route('student.learning'))->assertOk();
        $this->assertSame($hash, $student->fresh()->bound_device_hash);
    }

    public function test_a_different_browser_is_rejected_without_revoking_the_approved_session(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $device = StudentDevice::sole();
        $boundHash = $student->fresh()->bound_device_hash;
        auth('student')->logout();
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'other-browser'));

        $this->post('/login', ['token' => $issued['token']])->assertSessionHasErrors(['token' => 'الحساب مرتبط بجهاز ومتصفح معتمدين. اطلب من الأستاذ السماح بتغيير الجهاز.']);
        $this->assertGuest('student');
        $this->assertNull($device->fresh()->revoked_at);
        $this->assertSame($boundHash, $student->fresh()->bound_device_hash);
        $this->assertSame(1, $student->devices()->count());
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'device_login_blocked']);
    }

    public function test_a_missing_or_cleared_cookie_does_not_release_the_binding(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $hash = $student->fresh()->bound_device_hash;
        $this->post(route('student.logout'));
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, '');

        $this->post('/login', ['token' => $issued['token']])->assertSessionHasErrors('token');
        $this->assertGuest('student');
        $this->assertSame($hash, $student->fresh()->bound_device_hash);
    }

    public function test_changing_network_or_updating_the_browser_does_not_change_the_binding(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->withHeader('User-Agent', 'Chrome/140')->loginStudent($issued['token']);
        $hash = $student->fresh()->bound_device_hash;
        $this->post(route('student.logout'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.24'])->withHeader('User-Agent', 'Chrome/154');

        $this->loginStudent($issued['token'])->assertRedirect(route('student.learning'));
        $this->get(route('student.learning'))->assertOk();
        $this->assertSame($hash, $student->fresh()->bound_device_hash);
    }

    public function test_replacing_a_token_does_not_authorize_a_new_browser(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $hash = $student->fresh()->bound_device_hash;
        $replacement = app(StudentTokenService::class)->issue($student);
        auth('student')->logout();
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'other-browser'));

        $this->post('/login', ['token' => $replacement['token']])->assertSessionHasErrors('token');
        $this->assertSame($hash, $student->fresh()->bound_device_hash);
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'test-browser'));
        $this->loginStudent($replacement['token'])->assertRedirect(route('student.learning'));
    }

    public function test_a_session_without_the_approved_browser_cookie_cannot_read_protected_content(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'other-browser'));

        $this->get(route('student.account'))->assertRedirect(route('student.login'));
        $this->assertGuest('student');
    }

    public function test_teacher_permission_revokes_old_sessions_and_binds_only_the_next_successful_browser(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $device = StudentDevice::sole();
        $lesson = Lesson::factory()->create();
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 42]);
        $teacher = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($teacher, 'web')->post(route('admin.students.device-change', $student), ['confirmed' => true])->assertRedirect(route('admin.students.show', $student))->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->bound_device_hash);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertSame('active', $issued['record']->fresh()->status);
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'last_position_seconds' => 42]);
        $this->get(route('student.learning'))->assertRedirect(route('student.login'));
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'new-browser'));
        $this->loginStudent($issued['token'])->assertRedirect(route('student.learning'));
        $this->assertSame(hash('sha256', hash('sha256', 'new-browser')), $student->fresh()->bound_device_hash);
        $this->post(route('student.logout'));
        $this->withCookie(StudentDeviceBindingService::COOKIE_NAME, hash('sha256', 'test-browser'));
        $this->post('/login', ['token' => $issued['token']])->assertSessionHasErrors('token');
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'device_change_allowed']);
    }

    public function test_device_change_requires_teacher_permission_and_explicit_confirmation(): void
    {
        $student = Student::factory()->create(['bound_device_hash' => hash('sha256', 'bound')]);
        $this->post(route('admin.students.device-change', $student), ['confirmed' => true])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(), 'web')->post(route('admin.students.device-change', $student), ['confirmed' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->post(route('admin.students.device-change', $student), ['confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->assertSame(hash('sha256', 'bound'), $student->fresh()->bound_device_hash);
    }

    public function test_legacy_session_requires_login_to_establish_the_binding(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $device = StudentDevice::create(['student_id' => $student->id, 'student_token_id' => $issued['record']->id, 'session_id' => 'legacy']);
        $this->actingAs($student, 'student')->withSession(['student_token_id' => $issued['record']->id, 'student_device_id' => $device->id]);

        $this->get(route('student.learning'))->assertRedirect(route('student.login'));
        $this->assertNull($student->fresh()->bound_device_hash);
        $this->loginStudent($issued['token'])->assertRedirect(route('student.agreement.show'));
        $this->assertNotNull($student->fresh()->bound_device_hash);
    }

    public function test_notification_polling_does_not_consume_device_change_rate_limit(): void
    {
        $student = Student::factory()->create(['bound_device_hash' => hash('sha256', 'bound')]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        for ($i = 0; $i < 6; $i++) {
            $this->getJson(route('admin.notifications.unread-count'))->assertOk();
        }

        $this->post(route('admin.students.device-change', $student), ['confirmed' => true])
            ->assertRedirect(route('admin.students.show', $student))->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->bound_device_hash);
    }
}
