<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\StudentToken;
use App\Models\User;
use App\Services\StudentAuditService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class StudentSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_sets_profile_and_duration_and_can_renew_without_losing_token_or_progress(): void
    {
        $this->freezeTime();
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $data = ['name' => 'علي أحمد حسن كامل', 'college' => 'كلية طب الأسنان', 'phone' => '+9647701234567', 'study_grade_level_id' => $grade->id, 'access_type' => 'courses', 'course_ids' => [$course->id], 'subscription_duration' => '30'];
        $this->post(route('admin.students.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $student = Student::where('name', $data['name'])->sole();
        $this->assertSame($data['college'], $student->college);
        $this->assertSame($data['phone'], $student->phone);
        $this->assertSame($grade->id, $student->study_grade_level_id);
        $this->assertNull($student->grade_level_id);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $student->subscription_expires_at->toDateTimeString());
        $token = $student->tokens()->sole();
        $progress = LessonProgress::create(['student_id' => $student->id, 'lesson_id' => Lesson::factory()->for($course)->create()->id, 'last_position_seconds' => 51, 'watched_seconds' => 47]);
        $this->put(route('admin.students.update', $student), [...$data, 'subscription_duration' => 'custom', 'subscription_expires_at' => now()->addDays(60)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(60)->toDateTimeString(), $student->fresh()->subscription_expires_at->toDateTimeString());
        $this->put(route('admin.students.update', $student), [...$data, 'subscription_duration' => 'unlimited'])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->subscription_expires_at);
        $this->assertSame($token->id, $student->tokens()->sole()->id);
        $this->assertSame(51, $progress->fresh()->last_position_seconds);
    }

    public function test_expiry_terminates_active_session_and_blocks_login_and_course_policy(): void
    {
        $this->freezeTime();
        $student = Student::factory()->create(['subscription_expires_at' => now()->addMinute()]);
        $course = Course::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student)['token'];
        $this->loginStudent($token);
        $this->get('/learning')->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/learning')->assertStatus(423)->assertJsonPath('message', 'انتهت مدة اشتراكك. تواصل مع الأستاذ لتجديدها.');
        $this->assertGuest('student');
        $this->assertNotNull($student->devices()->sole()->revoked_at);
        $this->assertFalse(Gate::forUser($student->fresh())->allows('viewByStudent', $course));
        $this->post('/login', ['token' => $token])->assertSessionHasErrors(['token' => 'رمز الدخول غير صالح أو منتهي.']);
    }

    public function test_short_tokens_have_all_categories_and_are_hashed_and_legacy_tokens_remain_valid(): void
    {
        $student = Student::factory()->create(['name' => 'نور أحمد عبد الله']);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->assertSame(8, strlen($issued['token']));
        foreach (['/[A-Za-z]/', '/[0-9]/', '/[!@#$%*+_\-]/'] as $pattern) {
            $this->assertMatchesRegularExpression($pattern, $issued['token']);
        }
        $this->assertSame(hash('sha256', $issued['token']), $issued['record']->token_hash);
        $this->assertStringStartsWith($student->name.' · ', app(StudentAuditService::class)->watermarkText($student));
        $legacy = str_repeat('a', 64);
        StudentToken::create(['student_id' => $student->id, 'token_hash' => hash('sha256', $legacy), 'status' => 'active', 'device_limit' => 1]);
        $this->loginStudent($legacy)->assertRedirect();
        $this->assertAuthenticatedAs($student, 'student');
    }

    public function test_invalid_profile_and_expiry_and_unprivileged_administrator_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->postJson(route('admin.students.store'), ['name' => 'طالب', 'college' => '', 'phone' => 'bad', 'study_grade_level_id' => 999, 'subscription_duration' => 'custom', 'subscription_expires_at' => now()->subDay()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['college', 'phone', 'study_grade_level_id', 'subscription_expires_at']);
        $this->actingAs(User::factory()->create(), 'web')->postJson(route('admin.students.store'), ['name' => 'طالب'])->assertForbidden();
        $this->assertDatabaseCount('students', 0);
    }
}
