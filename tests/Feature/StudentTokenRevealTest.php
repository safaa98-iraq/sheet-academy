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

    public function test_professor_can_reveal_encrypted_token_without_exposing_it_in_the_table(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->assertNotSame($issued['token'], $issued['record']->getRawOriginal('encrypted_token'));
        $this->assertArrayNotHasKey('encrypted_token', $issued['record']->toArray());
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.index'))->assertOk()->assertSee('إدارة الطالب')->assertDontSee('data-reveal-student-token', false)->assertDontSee($issued['token']);
        $this->get(route('admin.students.show', $student))->assertOk()->assertSee('عرض التوكن ونسخه')->assertSee('حذف حساب الطالب')->assertDontSee($issued['token']);
        $response = $this->postJson(route('admin.students.token.reveal', $student))->assertOk()->assertExactJson(['token' => $issued['token']]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_old_hash_only_token_reports_that_regeneration_is_needed_without_revoking_it(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $issued['record']->update(['encrypted_token' => null]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->postJson(route('admin.students.token.reveal', $student))->assertUnprocessable()->assertJsonPath('message', 'هذا الرمز القديم محفوظ كتجزئة فقط ولا يمكن عرضه. أصدر توكناً جديداً ليصبح قابلاً للعرض والنسخ.');
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
