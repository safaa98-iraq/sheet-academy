<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAccountExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_professor_login_pages_have_separate_forms_and_no_cross_role_links(): void
    {
        $this->get(route('student.login'))->assertOk()->assertSee('تسجيل دخول الطالب')->assertSee('name="token"', false)
            ->assertSee(route('student.login.store'))->assertDontSee('name="password"', false)->assertDontSee(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertSee('تسجيل دخول البروفسور')->assertSee('name="email"', false)
            ->assertSee('name="password"', false)->assertSee(route('admin.login.store'))->assertDontSee('name="token"', false)->assertDontSee(route('student.login'));
    }

    public function test_a_student_session_cannot_enter_the_professor_dashboard_and_a_professor_cannot_enter_the_student_account(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.login.store'), ['token' => $issued['token']])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest('web');
        $this->post(route('student.logout'));
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->get(route('student.account'))->assertRedirect(route('student.login'));
        $this->get(route('admin.login'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_account_shows_only_the_logged_in_students_details_progress_and_current_access_dates(): void
    {
        $this->freezeTime();
        $grade = GradeLevel::factory()->create(['name' => 'المرحلة الثالثة']);
        $student = Student::factory()->create(['name' => 'زينب علي حسن', 'college' => 'كلية طب الأسنان', 'phone' => '+9647701234567', 'study_grade_level_id' => $grade->id, 'subscription_expires_at' => now()->addDays(30)]);
        $other = Student::factory()->create(['name' => 'بيانات طالب آخر سرية']);
        $course = Course::factory()->create(['title' => 'تشريح مخصص']);
        $draft = Course::factory()->create(['title' => 'مادة مسودة مخفية', 'publication_status' => 'draft', 'published_at' => null]);
        $student->courses()->attach([$course->id, $draft->id]);
        Course::factory()->create(['title' => 'مادة غير مخصصة']);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'duration_seconds' => 100, 'body_html' => '<p>محتوى المحاضرة لا يسرّب في الحساب</p>']);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'watched_seconds' => 50, 'last_position_seconds' => 42]);
        LessonProgress::create(['student_id' => $other->id, 'lesson_id' => $lesson->id, 'watched_seconds' => 100, 'completed_at' => now()]);
        $issued = app(StudentTokenService::class)->issue($student, null, now()->addDays(15));
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/154.0.0.0 Safari/537.36');
        $this->loginStudent($issued['token']);

        $response = $this->get(route('student.account', ['student_id' => $other->id]))->assertOk()->assertSee('زينب علي حسن')
            ->assertSee('كلية طب الأسنان')->assertSee('+9647701234567')->assertSee('المرحلة الثالثة')->assertSee('50٪')->assertSee('00:42')->assertSee('كروم · ويندوز')->assertDontSee('Mozilla/5.0')
            ->assertSee($student->subscription_expires_at->copy()->timezone('Asia/Baghdad')->format('Y-m-d'))
            ->assertSee(now()->addDays(15)->timezone('Asia/Baghdad')->format('Y-m-d H:i'))
            ->assertDontSee($other->name)->assertDontSee($draft->title)->assertDontSee('مادة غير مخصصة')
            ->assertDontSee($issued['token'])->assertDontSee($student->tokens()->first()->token_hash)->assertDontSee('محتوى المحاضرة لا يسرّب في الحساب');
        $response->assertViewHas('accountTotals', ['courses' => 1, 'completed' => 0, 'minutes' => 0]);
        $this->get('/student/account/'.$other->id)->assertNotFound();
        $this->get(route('student.learning'))->assertSee(route('student.account'))->assertSee('حسابي وبياناتي');
    }

    public function test_account_handles_empty_courses_and_escapes_untrusted_personal_details(): void
    {
        $student = Student::factory()->create(['college' => '<script>alert("bad")</script>']);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->get(route('student.account'))->assertOk()->assertSee('رحلتك تبدأ بمادتك الأولى')->assertSee($student->college)->assertDontSee($student->college, false);
    }

    public function test_expiry_and_account_freezing_still_block_the_personal_account(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $student->update(['subscription_expires_at' => now()->subMinute()]);
        $this->actingAs($student->fresh(), 'student');

        $this->get(route('student.account'))->assertRedirect(route('student.login'))->assertSessionHasErrors('token');
        $this->assertGuest('student');
        $student->update(['subscription_expires_at' => null]);
        $this->loginStudent($issued['token']);
        $student->update(['status' => 'frozen']);
        $this->actingAs($student->fresh(), 'student');
        $this->get(route('student.account'))->assertRedirect(route('student.login'));
        $this->assertGuest('student');
    }

    public function test_read_only_administrators_cannot_see_edit_renew_or_delete_actions_on_student_details(): void
    {
        $this->seed(PermissionsAndRolesSeeder::class);
        $viewerRole = Role::where('name', 'viewer')->first();
        if ($viewerRole === null) {
            $viewerRole = Role::create(['name' => 'account-viewer', 'label' => 'مشاهدة الطلاب']);
            $viewerRole->permissions()->attach(Permission::where('name', 'students.view')->firstOrFail());
        }
        $viewer = User::factory()->create();
        $viewer->roles()->attach($viewerRole);
        $student = Student::factory()->create();

        $this->actingAs($viewer, 'web')->get(route('admin.students.show', $student))->assertOk()->assertSee($student->name)
            ->assertDontSee(route('admin.students.edit', $student))->assertDontSee('تجديد الاشتراك')->assertDontSee('حذف حساب الطالب')->assertDontSee('إصدار توكن جديد');
    }
}
