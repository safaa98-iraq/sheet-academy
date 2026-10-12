<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTokenRevealTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_encrypted_at_rest_and_authorized_professor_can_copy_it_later(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->assertNotSame($issued['token'], $issued['record']->getRawOriginal('encrypted_token'));
        $this->assertSame($issued['token'], app(StudentTokenService::class)->reveal($issued['record']));
        $this->assertSame(hash('sha256', $issued['token']), $issued['record']->token_hash);
        $this->assertArrayNotHasKey('encrypted_token', $issued['record']->toArray());
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.index'))->assertOk()->assertSee('إدارة الطالب')->assertDontSee('data-reveal-student-token', false)->assertSee($issued['token']);
        $this->get(route('admin.students.show', $student))->assertOk()->assertDontSee('عرض التوكن ونسخه')->assertSee('حذف حساب الطالب')->assertSee($issued['token']);
        $response = $this->postJson(route('admin.students.token.reveal', $student))->assertOk()->assertJsonPath('token', $issued['token']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_old_hash_only_token_reports_that_regeneration_is_needed_without_revoking_it(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $issued['record']->update(['encrypted_token' => null]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->postJson(route('admin.students.token.reveal', $student))->assertUnprocessable()->assertJsonPath('message', 'الرمز القديم محفوظ كتجزئة فقط. استبدله من صفحة التجديد مرة واحدة ليصبح قابلاً للنسخ.');
        $this->assertSame('active', $issued['record']->fresh()->status);
    }

    public function test_token_reveal_requires_student_management_permission(): void
    {
        $student = Student::factory()->create();
        app(StudentTokenService::class)->issue($student);
        $this->postJson(route('admin.students.token.reveal', $student))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_super_admin' => false]), 'web');
        $this->postJson(route('admin.students.token.reveal', $student))->assertForbidden();
    }
}
