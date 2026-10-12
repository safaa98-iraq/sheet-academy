<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Services\LessonPublicationService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentLectureNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_lecture_notifies_only_active_assigned_students_and_never_duplicates(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->create(['grade_level_id' => $grade->id]);
        $assigned = Student::factory()->create();
        $assigned->courses()->attach($course);
        $wholeGrade = Student::factory()->create(['grade_level_id' => $grade->id]);
        $unassigned = Student::factory()->create(['study_grade_level_id' => $grade->id]);
        $expired = Student::factory()->create(['subscription_expires_at' => now()->subSecond()]);
        $expired->courses()->attach($course);
        $frozen = Student::factory()->create(['status' => 'frozen']);
        $frozen->courses()->attach($course);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'publication_status' => 'published', 'notify_students' => true]);
        $service = app(LessonPublicationService::class);
        $service->notify($lesson);
        $service->notify($lesson);
        foreach ([$assigned, $wholeGrade] as $student) {
            $this->assertSame(1, $student->notifications()->count());
            $this->assertSame($lesson->id, $student->notifications()->sole()->data['lesson_id']);
        }
        foreach ([$unassigned, $expired, $frozen] as $student) {
            $this->assertSame(0, $student->notifications()->count());
        }
    }

    public function test_video_alert_waits_for_ready_callback_and_hidden_ancestors_wait_for_publication(): void
    {
        config(['services.video_worker.token' => 'test-worker-token']);
        $lesson = Lesson::factory()->create(['publication_status' => 'published', 'notify_students' => true]);
        $student = Student::factory()->create();
        $student->courses()->attach($lesson->course);
        $root = CurriculumNode::create(['course_id' => $lesson->course_id, 'type' => 'material', 'title' => 'مادة', 'publication_status' => 'draft']);
        $root->children()->create(['course_id' => $lesson->course_id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published']);
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'queued', 'job_id' => (string) Str::uuid()]);
        app(LessonPublicationService::class)->notify($lesson);
        $this->assertSame(0, $student->notifications()->count());
        $callback = ['video_id' => $video->id, 'lesson_id' => $lesson->id, 'job_id' => $video->job_id, 'status' => 'ready', 'resolutions' => [360]];
        $this->withToken('test-worker-token')->postJson(route('internal.video-processing.update'), $callback)->assertOk();
        $this->assertNull($lesson->fresh()->notified_at);
        $root->update(['publication_status' => 'published']);
        $this->withToken('test-worker-token')->postJson(route('internal.video-processing.update'), $callback)->assertOk();
        $this->withToken('test-worker-token')->postJson(route('internal.video-processing.update'), $callback)->assertOk();
        $this->assertSame(1, $student->notifications()->count());
    }

    public function test_scheduled_alert_is_sent_when_due_and_existing_lectures_are_not_mass_notified(): void
    {
        $this->freezeTime();
        $course = Course::factory()->create();
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        Lesson::factory()->for($course)->create(['type' => 'text', 'publication_status' => 'published']);
        $scheduled = Lesson::factory()->for($course)->create(['type' => 'text', 'publication_status' => 'scheduled', 'is_published' => false, 'scheduled_at' => now()->addMinute(), 'notify_students' => true]);
        $this->artisan('app:notify-published-lectures')->assertSuccessful();
        $this->assertSame(0, $student->notifications()->count());
        $this->travel(2)->minutes();
        $this->artisan('app:notify-published-lectures')->assertSuccessful();
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame($scheduled->id, $student->notifications()->sole()->data['lesson_id']);
    }

    public function test_student_can_read_only_own_notifications_and_revoked_course_has_no_link(): void
    {
        $lesson = Lesson::factory()->create(['type' => 'text', 'publication_status' => 'published', 'notify_students' => true, 'title' => '<script>alert(1)</script>']);
        $student = Student::factory()->create();
        $other = Student::factory()->create();
        foreach ([$student, $other] as $recipient) {
            $recipient->courses()->attach($lesson->course);
        }
        app(LessonPublicationService::class)->notify($lesson);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.notifications'))->assertOk()->assertSee($lesson->title)->assertDontSee($lesson->title, false);
        $own = $student->notifications()->sole();
        $foreign = $other->notifications()->sole();
        $this->post(route('student.notifications.read', $foreign->id))->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
        $this->post(route('student.notifications.read', $own->id))->assertRedirect();
        $this->assertNotNull($own->fresh()->read_at);
        $student->courses()->detach();
        $this->get(route('student.notifications'))->assertOk()->assertViewHas('links', []);
    }
}
