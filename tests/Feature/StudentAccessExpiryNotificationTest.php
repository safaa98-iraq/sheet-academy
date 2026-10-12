<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentAccessExpired;
use App\Notifications\SuspiciousStudentActivity;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentAccessExpiryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_is_detected_without_student_login_and_alerts_only_authorized_active_admins_once(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $manager = $this->adminWithPermissions(['students.manage']);
        $unauthorized = User::factory()->create();
        $inactive = User::factory()->create(['is_super_admin' => true, 'is_active' => false]);
        $student = Student::factory()->create(['name' => 'علي أحمد', 'phone' => '+9647701234567', 'subscription_expires_at' => now()->addMinute()]);
        Student::factory()->create(['subscription_expires_at' => null]);
        Student::factory()->create(['subscription_expires_at' => now()->subDay(), 'status' => 'frozen']);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertSame(0, $teacher->notifications()->count());
        $this->travel(2)->minutes();
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        foreach ([$teacher, $manager] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame(StudentAccessExpired::class, $notification->type);
            $this->assertSame('subscription', $notification->data['kind']);
            $this->assertSame($student->id, $notification->data['student_id']);
            $this->assertSame('+9647701234567', $notification->data['phone']);
        }
        $this->assertSame(0, $unauthorized->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertNotNull($student->fresh()->subscription_expiry_notified_at);
        $this->actingAs($manager, 'web')->getJson(route('admin.notifications.unread-count'))->assertOk()->assertJsonPath('count', 1);
        $this->get(route('admin.notifications.index'))->assertOk()->assertSee('تجديد التفعيل وبيانات الطالب');
    }

    public function test_code_expiry_alerts_once_and_revoked_codes_and_duplicate_subscription_expiry_are_skipped(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create();
        $token = app(StudentTokenService::class)->issue($student, expiresAt: now()->addMinute())['record'];
        $revokedStudent = Student::factory()->create();
        $revoked = app(StudentTokenService::class)->issue($revokedStudent, expiresAt: now()->addMinute())['record'];
        app(StudentTokenService::class)->issue($revokedStudent);
        $bothExpired = Student::factory()->create(['subscription_expires_at' => now()->addMinute()]);
        app(StudentTokenService::class)->issue($bothExpired, expiresAt: now()->addMinute());
        $this->travel(2)->minutes();
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertSame(2, $teacher->notifications()->count());
        $this->assertSame(1, $teacher->notifications()->where('data->kind', 'token')->count());
        $this->assertSame($student->id, $teacher->notifications()->where('data->kind', 'token')->sole()->data['student_id']);
        $this->assertNotNull($token->fresh()->expiry_notified_at);
        $this->assertNull($revoked->fresh()->expiry_notified_at);
        $this->assertSame('revoked', $revoked->fresh()->status);
        $this->assertSame(1, $teacher->notifications()->where('data->student_id', $bothExpired->id)->count());
    }

    public function test_renewal_resets_alert_marker_but_ordinary_profile_edit_does_not_repeat_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create(['subscription_expires_at' => '2026-10-10 10:00:00']);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->actingAs($teacher, 'web');
        $data = ['name' => 'علي محدّث', 'subscription_duration' => 'custom', 'subscription_expires_at' => '2026-10-10'];
        $this->put(route('admin.students.update', $student), $data)->assertSessionHasNoErrors();
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertSame(1, $teacher->notifications()->count());
        $this->put(route('admin.students.update', $student), [...$data, 'subscription_expires_at' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->subscription_expiry_notified_at);
        $this->travelTo(Carbon::parse('2026-11-20 21:00:00', 'UTC'));
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertSame(2, $teacher->notifications()->count());
        $this->assertNotNull($student->fresh()->subscription_expiry_notified_at);
    }

    public function test_expiry_without_eligible_admin_is_not_marked_until_delivery_is_possible(): void
    {
        $this->freezeTime();
        $student = Student::factory()->create(['subscription_expires_at' => now()->subDay()]);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertNull($student->fresh()->subscription_expiry_notified_at);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->assertSame(1, $teacher->notifications()->count());
    }

    public function test_admin_inbox_exposes_contact_and_renewal_actions_safely_and_read_is_owner_scoped(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $other = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create(['name' => '<script>alert(1)</script>', 'phone' => '+9647701234567', 'subscription_expires_at' => now()->subDay()]);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $this->actingAs($teacher, 'web')->get(route('admin.notifications.index'))->assertOk()
            ->assertSee('تجديد التفعيل وبيانات الطالب')->assertSee('tel:+9647701234567', false)->assertSee($student->name)->assertDontSee($student->name, false);
        $this->assertSame(1, $teacher->unreadNotifications()->count());
        $this->post(route('admin.notifications.read', $other->notifications()->sole()->id))->assertNotFound();
        $this->assertNull($other->notifications()->sole()->read_at);
        $own = $teacher->notifications()->sole();
        $this->post(route('admin.notifications.read', $own->id))->assertRedirect();
        $this->assertNotNull($own->fresh()->read_at);
    }

    public function test_audit_page_does_not_mark_subscription_alerts_as_read(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        Student::factory()->create(['subscription_expires_at' => now()->subDay()]);
        $this->artisan('app:notify-expired-student-access')->assertSuccessful();
        $teacher->notify(new SuspiciousStudentActivity(['summary' => 'نشاط تجريبي']));
        $this->actingAs($teacher, 'web')->get(route('admin.activity'))->assertOk();
        $this->assertSame(StudentAccessExpired::class, $teacher->unreadNotifications()->sole()->type);
    }

    private function adminWithPermissions(array $names): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'student-manager', 'label' => 'مدير الطلاب', 'guard_name' => 'web']);
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'section' => 'students']);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return $user;
    }
}
