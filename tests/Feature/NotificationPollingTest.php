<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentAccessExpired;
use App\Services\LessonPublicationService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_live_feed_contains_only_own_lecture_alerts_and_updates_after_reading(): void
    {
        $lesson = Lesson::factory()->create(['type' => 'text', 'publication_status' => 'published', 'notify_students' => true]);
        $student = Student::factory()->create();
        $other = Student::factory()->create();
        $student->courses()->attach($lesson->course);
        app(LessonPublicationService::class)->notify($lesson);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->getJson(route('student.notifications.unread-count'))->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('latest.id', $student->notifications()->sole()->id)->assertJsonPath('latest.message', 'محاضرة جديدة: '.$lesson->title.' — '.$lesson->course->title);
        $this->get(route('student.learning'))->assertOk()->assertSee('data-notifications-url', false)->assertSee('data-notification-arrival', false);
        $this->post(route('student.notifications.read', $student->notifications()->sole()->id))->assertRedirect();
        $this->getJson(route('student.notifications.unread-count'))->assertOk()->assertJsonPath('count', 0)->assertJsonPath('latest', null);
        $this->post(route('student.logout'))->assertRedirect();
        $this->loginStudent(app(StudentTokenService::class)->issue($other)['token']);
        $this->getJson(route('student.notifications.unread-count'))->assertOk()->assertJsonPath('count', 0)->assertJsonPath('latest', null);
    }

    public function test_admin_live_feed_requires_permission_and_scopes_to_authenticated_recipient(): void
    {
        $this->getJson(route('admin.notifications.unread-count'))->assertUnauthorized();
        $this->getJson(route('student.notifications.unread-count'))->assertUnauthorized();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create();
        $teacher->notify(new StudentAccessExpired($student, 'subscription', now()->subMinute()));
        $other = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($other, 'web')->getJson(route('admin.notifications.unread-count'))->assertOk()->assertJsonPath('count', 0);
        $this->actingAs($teacher, 'web')->getJson(route('admin.notifications.unread-count'))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('latest.id', $teacher->notifications()->sole()->id);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('التنبيهات والتجديد')->assertSee('data-notifications-url', false);
        $this->actingAs(User::factory()->create(), 'web')->getJson(route('admin.notifications.unread-count'))->assertForbidden();
        $this->get(route('admin.notifications.index'))->assertForbidden();
        $this->post(route('admin.notifications.read', $teacher->notifications()->sole()->id))->assertForbidden();
        $this->assertNull($teacher->notifications()->sole()->read_at);
    }

    public function test_expired_student_session_cannot_poll_notifications(): void
    {
        $this->freezeTime();
        $student = Student::factory()->create(['subscription_expires_at' => now()->addMinute()]);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->travel(2)->minutes();
        $this->getJson(route('student.notifications.unread-count'))->assertStatus(423);
        $this->assertGuest('student');
    }
}
