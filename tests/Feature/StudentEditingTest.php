<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_flexible_date_is_saved_at_end_of_baghdad_day_and_expiry_blocks_access(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
        $student = Student::factory()->create();
        $course = Course::factory()->create();
        $grade = GradeLevel::factory()->create();
        $token = app(StudentTokenService::class)->issue($student)['token'];
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.edit', $student))->assertOk()->assertSee('حفظ جميع التغييرات')->assertSee('type="date"', false)->assertSee('بتوقيت بغداد')->assertDontSee('30 يوماً')->assertSee('إجراءات الحساب ورمز الدخول');
        $this->put(route('admin.students.update', $student), ['name' => 'علي أحمد', 'college' => 'طب الأسنان', 'phone' => '+9647701234567', 'study_grade_level_id' => $grade->id, 'access_type' => 'courses', 'course_ids' => [$course->id], 'subscription_duration' => 'custom', 'subscription_expires_at' => '2026-11-19'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-11-19 20:59:59', $student->fresh()->subscription_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('علي أحمد', $student->fresh()->name);
        $this->assertSame([$course->id], $student->fresh()->courses->modelKeys());
        $this->loginStudent($token);
        $this->travelTo(Carbon::parse('2026-11-19 20:59:58', 'UTC'));
        $this->get('/learning')->assertOk();
        $this->travelTo(Carbon::parse('2026-11-19 21:00:00', 'UTC'));
        $this->getJson('/learning')->assertStatus(423)->assertJsonPath('message', 'انتهت مدة اشتراكك. تواصل مع الأستاذ لتجديدها.');
    }

    public function test_editing_expired_student_preserves_exact_expiry_token_and_progress_and_can_renew(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
        $student = Student::factory()->create(['subscription_expires_at' => '2026-10-01 10:30:00']);
        $token = app(StudentTokenService::class)->issue($student)['record'];
        $course = Course::factory()->create();
        $student->courses()->attach($course);
        $progress = LessonProgress::create(['student_id' => $student->id, 'lesson_id' => Lesson::factory()->for($course)->create()->id, 'last_position_seconds' => 73, 'watched_seconds' => 60]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $data = ['name' => 'اسم محدّث', 'access_type' => 'courses', 'course_ids' => [$course->id], 'subscription_duration' => 'custom', 'subscription_expires_at' => '2026-10-01'];
        $this->put(route('admin.students.update', $student), $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01 10:30:00', $student->fresh()->subscription_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('اسم محدّث', $student->fresh()->name);
        $this->assertFalse($student->fresh()->subscriptionIsActive());
        $this->put(route('admin.students.update', $student), [...$data, 'subscription_expires_at' => '2026-12-04'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-04 20:59:59', $student->fresh()->subscription_expires_at->format('Y-m-d H:i:s'));
        $this->assertTrue($student->fresh()->subscriptionIsActive());
        $this->assertSame($token->id, $student->tokens()->sole()->id);
        $this->assertSame(73, $progress->fresh()->last_position_seconds);
        $this->put(route('admin.students.update', $student), [...$data, 'subscription_duration' => 'unlimited', 'subscription_expires_at' => 'not a date'])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->subscription_expires_at);
    }

    public function test_date_required_and_past_or_invalid_dates_rejected_without_mutating_student(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
        $student = Student::factory()->create();
        $original = $student->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $data = ['name' => 'تغيير', 'subscription_duration' => 'custom'];
        $this->putJson(route('admin.students.update', $student), $data)->assertUnprocessable()->assertJsonValidationErrors(['subscription_expires_at']);
        $this->putJson(route('admin.students.update', $student), [...$data, 'subscription_expires_at' => '2026-10-10'])->assertUnprocessable()->assertJsonPath('errors.subscription_expires_at.0', 'اختر تاريخ انتهاء اليوم أو بعده لتجديد الاشتراك.');
        $this->putJson(route('admin.students.update', $student), [...$data, 'subscription_expires_at' => '2026-02-30'])->assertUnprocessable()->assertJsonPath('errors.subscription_expires_at.0', 'أدخل تاريخ انتهاء صحيحاً.');
        $this->assertSame($original, $student->fresh()->getAttributes());
        $this->actingAs(User::factory()->create(), 'web')->putJson(route('admin.students.update', $student), [...$data, 'subscription_expires_at' => '2026-12-01'])->assertForbidden();
        $this->assertSame($original, $student->fresh()->getAttributes());
    }

    public function test_creation_with_any_future_calendar_date_and_today_uses_baghdad_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 22:30:00', 'UTC'));
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.students.store'), ['name' => 'طالب جديد', 'subscription_duration' => 'custom', 'subscription_expires_at' => '2026-10-12'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-12 20:59:59', Student::sole()->subscription_expires_at->format('Y-m-d H:i:s'));
        $this->postJson(route('admin.students.store'), ['name' => 'طالب ثان', 'subscription_duration' => 'custom', 'subscription_expires_at' => '2026-10-11'])->assertUnprocessable()->assertJsonValidationErrors('subscription_expires_at');
        $this->assertDatabaseCount('students', 1);
    }
}
