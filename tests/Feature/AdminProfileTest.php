<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_updates_own_details_and_logs_in_with_new_credentials(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $other = User::factory()->create();
        $oldRememberToken = $admin->remember_token;
        $this->actingAs($admin, 'web')->get(route('admin.profile.edit'))->assertOk()->assertSee('حفظ بياناتي')->assertSee($admin->email);
        $this->put(route('admin.profile.update'), ['name' => 'الدكتور أحمد', 'email' => 'teacher@example.test', 'current_password' => 'password', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026', 'id' => $other->id])
            ->assertRedirect(route('admin.profile.edit'))->assertSessionHasNoErrors();
        $admin->refresh();
        $this->assertSame('الدكتور أحمد', $admin->name);
        $this->assertSame('teacher@example.test', $admin->email);
        $this->assertNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('NewPassword!2026', $admin->password));
        $this->assertNotSame($oldRememberToken, $admin->remember_token);
        $this->assertTrue($admin->is_super_admin);
        $this->assertTrue($admin->is_active);
        $this->assertNotSame('الدكتور أحمد', $other->fresh()->name);
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.login.store'), ['email' => 'teacher@example.test', 'password' => 'NewPassword!2026'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_name_only_update_keeps_password_and_verified_email(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $oldPassword = $admin->password;
        $this->actingAs($admin, 'web')->put(route('admin.profile.update'), ['name' => 'اسم جديد', 'email' => $admin->email, 'current_password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.profile.edit'));
        $this->assertSame('اسم جديد', $admin->fresh()->name);
        $this->assertSame($oldPassword, $admin->fresh()->password);
        $this->assertNotNull($admin->fresh()->email_verified_at);
    }

    #[DataProvider('invalidProfileChanges')]
    public function test_invalid_or_privileged_changes_are_rejected(array $changes, string $field, string $message): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $original = $admin->fresh()->getAttributes();
        $this->actingAs($admin, 'web')->put(route('admin.profile.update'), [...['name' => 'اسم جديد', 'email' => $admin->email, 'current_password' => 'password'], ...$changes])
            ->assertSessionHasErrors([$field => $message])->assertSessionMissing('_old_input.current_password');
        $this->assertSame($original, $admin->fresh()->getAttributes());
    }

    public static function invalidProfileChanges(): array
    {
        return [
            'wrong current password' => [['current_password' => 'wrong'], 'current_password', 'كلمة المرور الحالية غير صحيحة.'],
            'missing current password' => [['current_password' => ''], 'current_password', 'أدخل كلمة المرور الحالية لتأكيد التغيير.'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password', 'يجب ألا تقل كلمة المرور الجديدة عن 12 حرفاً.'],
            'confirmation mismatch' => [['password' => 'NewPassword!2026', 'password_confirmation' => 'different'], 'password', 'تأكيد كلمة المرور الجديدة غير مطابق.'],
            'disable account' => [['is_active' => false], 'is_active', 'لا يمكن تعطيل الحساب من هذه الصفحة.'],
            'change super admin' => [['is_super_admin' => false], 'is_super_admin', 'لا يمكن تغيير صلاحيات الحساب من هذه الصفحة.'],
            'change roles' => [['role_ids' => [1]], 'role_ids', 'لا يمكن تغيير صلاحيات الحساب من هذه الصفحة.'],
        ];
    }

    public function test_duplicate_email_is_rejected_and_user_content_is_escaped(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'name' => '<script>alert(1)</script>']);
        $other = User::factory()->create();
        $this->actingAs($admin, 'web')->get(route('admin.profile.edit'))->assertOk()->assertDontSee($admin->name, false)->assertSee($admin->name);
        $this->put(route('admin.profile.update'), ['name' => 'اسم جديد', 'email' => $other->email, 'current_password' => 'password'])
            ->assertSessionHasErrors(['email' => 'هذا البريد مسجّل لحساب آخر.']);
        $this->assertSame($admin->email, $admin->fresh()->email);
    }

    public function test_guests_students_ordinary_and_inactive_admins_cannot_edit_main_admin_profile(): void
    {
        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
        $this->putJson(route('admin.profile.update'), [])->assertUnauthorized();
        $this->actingAs(Student::factory()->create(), 'student')->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(), 'web')->get(route('admin.profile.edit'))->assertForbidden();
        $this->putJson(route('admin.profile.update'), [])->assertForbidden();
        Auth::guard('web')->logout();
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => false]), 'web')->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
    }
}
