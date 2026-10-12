<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use App\Services\SiteBrandService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfessorManagementUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_extend_keeps_the_same_token_session_enrollments_and_resume_position(): void
    {
        $this->freezeTime();
        $student = Student::factory()->create(['subscription_expires_at' => now()->addDay()]);
        $lesson = Lesson::factory()->create();
        $student->courses()->attach($lesson->course);
        $issued = app(StudentTokenService::class)->issue($student, expiresAt: now()->addDay());
        $this->loginStudent($issued['token']);
        $device = StudentDevice::sole();
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 83, 'watched_seconds' => 83]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.students.renew.edit', $student))->assertOk()->assertSee('تمديد الرمز الحالي');
        $date = now()->addDays(30)->format('Y-m-d');
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'extend', 'subscription_duration' => 'custom', 'subscription_expires_at' => $date])->assertSessionHasNoErrors()->assertRedirect(route('admin.students.show', $student));
        $this->assertSame($date.' 20:59:59', $student->fresh()->subscription_expires_at->format('Y-m-d H:i:s'));
        $this->assertTrue($issued['record']->fresh()->expires_at->equalTo($student->fresh()->subscription_expires_at));
        $this->assertSame($issued['token'], app(StudentTokenService::class)->reveal($issued['record']->fresh()));
        $this->assertNull($device->fresh()->revoked_at);
        $this->assertSame([$lesson->course_id], $student->courses()->pluck('courses.id')->all());
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 83]);
        $this->get(route('student.learning'))->assertOk();
    }

    public function test_replacement_revokes_the_old_token_and_session_and_is_copyable_without_losing_progress(): void
    {
        $student = Student::factory()->create();
        $old = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($old['token']);
        $device = StudentDevice::sole();
        $lesson = Lesson::factory()->create();
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 51]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'replace', 'subscription_duration' => 'unlimited'])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $old['record']->fresh()->status);
        $this->assertNotNull($device->fresh()->revoked_at);
        $new = $student->tokens()->latest('id')->first();
        $plain = app(StudentTokenService::class)->reveal($new);
        $this->assertNotSame($old['token'], $plain);
        $this->assertSame(8, strlen($plain));
        $this->get(route('admin.students.index'))->assertOk()->assertSee($plain)->assertSee('data-copy-target="#student-token-', false);
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'last_position_seconds' => 51]);
        $this->get(route('student.learning'))->assertRedirect(route('student.login'));
        $this->post(route('student.login.store'), ['token' => $old['token']])->assertSessionHasErrors('token');
    }

    public function test_renewal_preserves_a_freeze_until_reactivation_is_explicit(): void
    {
        $student = Student::factory()->create(['status' => 'frozen']);
        $issued = app(StudentTokenService::class)->issue($student);
        $issued['record']->update(['status' => 'frozen']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'replace', 'subscription_duration' => 'unlimited'])->assertSessionHasNoErrors();
        $this->assertSame('frozen', $student->fresh()->status);
        $token = $student->tokens()->latest('id')->first();
        $this->assertSame('frozen', $token->status);
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'extend', 'subscription_duration' => 'unlimited', 'reactivate' => true])->assertSessionHasNoErrors();
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame('active', $token->fresh()->status);
    }

    public function test_renewal_rejects_invalid_dates_and_missing_tokens_without_changing_subscription(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'replace', 'subscription_duration' => 'custom', 'subscription_expires_at' => '2000-01-01'])->assertSessionHasErrors('subscription_expires_at');
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'extend', 'subscription_duration' => 'unlimited'])->assertSessionHasErrors('mode');
        $this->assertNull($student->fresh()->subscription_expires_at);
        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_read_only_student_admin_cannot_see_or_reveal_codes_or_renew_or_send_reminders(): void
    {
        $permission = Permission::create(['name' => 'students.view', 'section' => 'students', 'label' => 'عرض الطلاب']);
        $role = Role::create(['name' => 'reader', 'label' => 'مشاهد', 'guard_name' => 'web']);
        $role->permissions()->attach($permission);
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->actingAs($reader, 'web');
        $this->get(route('admin.students.index'))->assertOk()->assertDontSee($issued['token']);
        $this->get(route('admin.students.show', $student))->assertOk()->assertDontSee($issued['token']);
        $this->get(route('admin.students.renew.edit', $student))->assertForbidden();
        $this->put(route('admin.students.renew.update', $student), ['mode' => 'replace', 'subscription_duration' => 'unlimited'])->assertForbidden();
        $this->postJson(route('admin.students.token.reveal', $student))->assertForbidden();
        $this->post(route('admin.students.subscription-reminder', $student))->assertForbidden();
        $this->assertSame('active', $issued['record']->fresh()->status);
    }

    public function test_forged_encrypted_code_is_not_shown_if_its_hash_does_not_match(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $issued['record']->update(['encrypted_token' => 'forged-code']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->postJson(route('admin.students.token.reveal', $student))->assertUnprocessable()->assertDontSee('forged-code');
        $this->get(route('admin.students.index'))->assertOk()->assertDontSee('forged-code');
    }

    public function test_course_cover_is_normalized_and_served_without_exposing_private_content(): void
    {
        Storage::fake('private');
        $course = Course::factory()->for(GradeLevel::factory())->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.courses.edit', $course))->assertOk()->assertSee('رفع صورة الغلاف');
        $this->put(route('admin.courses.update', $course), ['grade_level_id' => $course->grade_level_id, 'title' => $course->title, 'publication_status' => 'published', 'cover' => UploadedFile::fake()->image('cover.png', 2000, 1200)])->assertSessionHasNoErrors();
        $cover = $course->fresh()->cover_image;
        $this->assertStringStartsWith('/brand-assets/', $cover);
        $this->get($cover)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $info = getimagesize(Storage::disk('private')->path(ltrim($cover, '/')));
        $this->assertSame(1600, $info[0]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($cover, false);
        Storage::disk('private')->put('attachments/secret.pdf', 'secret');
        $this->get('/brand-assets/secret.pdf')->assertNotFound();
        $this->get('/brand-assets/..%2Fattachments%2Fsecret.pdf')->assertNotFound();
    }

    public function test_cover_validation_and_authorization_prevent_unsafe_or_unprivileged_uploads(): void
    {
        Storage::fake('private');
        $course = Course::factory()->for(GradeLevel::factory())->create();
        $payload = ['grade_level_id' => $course->grade_level_id, 'title' => $course->title, 'publication_status' => 'published'];
        $this->actingAs(User::factory()->create(), 'web')->put(route('admin.courses.update', $course), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.courses.update', $course), $payload + ['cover' => UploadedFile::fake()->createWithContent('image.png', '<svg onload="alert(1)"></svg>')])->assertSessionHasErrors('cover');
        $this->put(route('admin.courses.update', $course), $payload + ['cover' => UploadedFile::fake()->image('large.jpg')->size(5121)])->assertSessionHasErrors('cover');
        $this->assertSame([], Storage::disk('private')->allFiles('brand-assets'));
    }

    public function test_removing_a_cover_is_explicit_and_editing_other_fields_preserves_it(): void
    {
        $course = Course::factory()->for(GradeLevel::factory())->create(['cover_image' => '/images/example.png']);
        $payload = ['grade_level_id' => $course->grade_level_id, 'title' => 'عنوان جديد', 'publication_status' => 'published'];
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.courses.update', $course), $payload)->assertSessionHasNoErrors();
        $this->assertSame('/images/example.png', $course->fresh()->cover_image);
        $this->put(route('admin.courses.update', $course), $payload + ['remove_cover' => true])->assertSessionHasNoErrors();
        $this->assertNull($course->fresh()->cover_image);
    }

    public function test_settings_update_name_logo_manifest_and_contact_details_with_cache_invalidation_and_escaping(): void
    {
        Storage::fake('private');
        $this->get(route('student.login'))->assertOk()->assertSee('عيادة التعلّم');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('رفع اللوغو');
        $payload = $this->settingsPayload();
        $payload['name'] = 'أكاديمية <script>alert(1)</script>';
        $this->put(route('admin.settings.update'), $payload + ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        $settings = SiteSetting::findOrFail(1);
        $this->get(route('student.login'))->assertOk()->assertSee($payload['name'])->assertDontSee($payload['name'], false)->assertSee(route('branding.image', basename($settings->logo_path)), false);
        $this->get(route('branding.manifest'))->assertOk()->assertJsonPath('name', $payload['name'])->assertJsonPath('dir', 'rtl');
        $this->get(route('branding.image', basename($settings->logo_path)))->assertOk();
        $this->put(route('admin.settings.update'), $this->settingsPayload() + ['remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull($settings->fresh()->logo_path);
        $this->get(route('student.login'))->assertOk()->assertSee('أكاديمية جديدة')->assertDontSee($payload['name']);
        $student = Student::factory()->create();
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.account'))->assertOk()->assertSee('+9647701234567')->assertSee('contact@example.com');
    }

    public function test_settings_are_protected_and_invalid_logo_is_rejected(): void
    {
        Storage::fake('private');
        $this->get(route('admin.settings.edit'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(), 'web')->get(route('admin.settings.edit'))->assertForbidden();
        $this->put(route('admin.settings.update'), $this->settingsPayload())->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.settings.update'), $this->settingsPayload() + ['logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;')])->assertSessionHasErrors('logo');
        $this->assertSame(0, SiteSetting::count());
        $this->assertSame([], Storage::disk('private')->allFiles('brand-assets'));
    }

    public function test_manual_reminder_appears_only_in_the_students_account_and_polling_uses_the_correct_message(): void
    {
        $student = Student::factory()->create(['subscription_expires_at' => now()->addDays(5)]);
        $other = Student::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $message = 'للتجديد تواصل معي <script>alert(1)</script>';
        $this->post(route('admin.students.subscription-reminder', $student), ['message' => $message])->assertSessionHasNoErrors();
        $notice = $student->notifications()->sole();
        $this->assertSame('subscription_reminder', $notice->data['kind']);
        $this->assertSame($message, $notice->data['message']);
        $this->assertSame(0, $other->notifications()->count());
        $this->assertNotNull($student->fresh()->subscription_reminded_at);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.notifications'))->assertOk()->assertSee($message)->assertDontSee($message, false)->assertSee('تفاصيل اشتراكي')->assertDontSee('المحاضرة غير متاحة');
        $this->getJson(route('student.notifications.unread-count'))->assertOk()->assertJsonPath('latest.message', $message);
        $this->post(route('student.logout'))->assertRedirect();
        $this->loginStudent(app(StudentTokenService::class)->issue($other)['token']);
        $this->post(route('student.notifications.read', $notice->id))->assertNotFound();
    }

    public function test_manual_reminder_rejects_expired_or_unlimited_subscriptions(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        foreach ([null, now()->subDay()] as $expiry) {
            $student = Student::factory()->create(['subscription_expires_at' => $expiry]);
            $this->post(route('admin.students.subscription-reminder', $student))->assertSessionHasErrors('reminder');
            $this->assertSame(0, $student->notifications()->count());
        }
    }

    public function test_automatic_reminders_are_opt_in_deduplicated_and_reset_after_renewal(): void
    {
        $this->freezeTime();
        $student = Student::factory()->create(['subscription_expires_at' => now()->addDays(5)]);
        $far = Student::factory()->create(['subscription_expires_at' => now()->addDays(20)]);
        $frozen = Student::factory()->create(['status' => 'frozen', 'subscription_expires_at' => now()->addDays(4)]);
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->assertSame(0, $student->notifications()->count());
        SiteSetting::factory()->create(['id' => 1, 'subscription_reminder_enabled' => true]);
        app(SiteBrandService::class)->forget();
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $far->notifications()->count());
        $this->assertSame(0, $frozen->notifications()->count());
        app(StudentTokenService::class)->issue($student);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->put(route('admin.students.renew.update', $student), ['mode' => 'extend', 'subscription_duration' => 'custom', 'subscription_expires_at' => now()->addDays(6)->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->subscription_reminder_for);
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->assertSame(2, $student->notifications()->count());
    }

    public function test_saving_the_same_expiry_does_not_repeat_an_automatic_reminder(): void
    {
        $this->freezeTime();
        $expiry = now()->addDays(5)->timezone('Asia/Baghdad')->endOfDay()->utc()->startOfSecond();
        $student = Student::factory()->create(['subscription_expires_at' => $expiry]);
        SiteSetting::factory()->create(['id' => 1, 'subscription_reminder_enabled' => true]);
        app(StudentTokenService::class)->issue($student, expiresAt: $expiry);
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->put(route('admin.students.renew.update', $student), ['mode' => 'extend', 'subscription_duration' => 'custom', 'subscription_expires_at' => $expiry->copy()->timezone('Asia/Baghdad')->toDateString()])->assertSessionHasNoErrors();
        $this->artisan('app:notify-upcoming-subscriptions')->assertSuccessful();
        $this->assertSame(1, $student->notifications()->count());
        $this->assertTrue($student->fresh()->subscription_reminder_for->equalTo($expiry));
    }

    private function settingsPayload(): array
    {
        return ['name' => 'أكاديمية جديدة', 'tagline' => 'تعليم يصنع الفرق', 'description' => 'منصة الأستاذ', 'contact_phone' => '+9647701234567', 'contact_email' => 'contact@example.com', 'subscription_reminder_enabled' => false, 'subscription_reminder_days' => 7];
    }
}
