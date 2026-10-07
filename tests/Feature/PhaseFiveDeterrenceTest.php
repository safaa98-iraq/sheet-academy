<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use App\Notifications\SuspiciousStudentActivity;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFiveDeterrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_login_revokes_the_previous_device_session(): void
    {
        $student = Student::factory()->create();
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);
        $firstDevice = StudentDevice::query()->firstOrFail();

        auth('student')->logout();
        $this->loginStudent($token['token']);

        $this->assertNotNull($firstDevice->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'multiple_device_login']);
        $this->assertSame(1, StudentDevice::query()->where('student_id', $student->id)->whereNull('revoked_at')->count());
    }

    public function test_each_new_content_link_invalidates_the_previous_link(): void
    {
        $student = Student::factory()->create();
        [$course, $lesson] = $this->publishedLesson();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $firstUrl = $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])
            ->assertOk()->json('url');
        $secondUrl = $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])
            ->assertOk()->json('url');

        $this->get($secondUrl)->assertOk()->assertSee($lesson->title);
        $this->get($firstUrl)->assertNotFound();
    }

    public function test_suspicious_activity_is_recorded_and_can_suspend_at_configured_score(): void
    {
        config(['audit.auto_suspend_score' => 3]);
        $student = Student::factory()->create();
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $this->postJson(route('student.activity.store'), ['event' => 'suspicious_devtools'])->assertStatus(423);
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'suspicious_devtools', 'points' => 3]);
        $this->assertSame('suspended', $student->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'automatic_suspension']);
        $this->assertSame('revoked', $token['record']->fresh()->status);
    }

    public function test_stale_or_unassigned_student_links_are_not_issued(): void
    {
        $student = Student::factory()->create();
        [, $lesson] = $this->publishedLesson();
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])->assertNotFound();
    }

    public function test_pdf_viewer_renders_private_pages_and_uses_signed_short_lived_links(): void
    {
        Storage::fake('private');
        Process::fake(function (PendingProcess $process): string {
            if (str_contains((string) $process->command[0], 'pdfinfo')) {
                return "Pages:          1\n";
            }

            $prefix = $process->command[array_key_last($process->command)];
            file_put_contents($prefix.'-1.png', 'private-rendered-page');

            return '';
        });

        $student = Student::factory()->create();
        [$course, $lesson] = $this->publishedLesson();
        $student->courses()->attach($course);
        Storage::disk('private')->put('lessons/notes.pdf', '%PDF-1.7 fake source');
        $attachment = LessonAttachment::query()->create([
            'lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'lessons/notes.pdf',
            'original_name' => 'notes.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 20,
        ]);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $viewerUrl = $this->issueStudentViewLink('document', $attachment->id);

        $viewer = $this->get($viewerUrl)->assertOk()->assertSee('data-document-image', false);
        preg_match('/src="([^"]+\/documents\/[^" ]+\/pages\/1\?[^" ]+)"/', $viewer->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $imageUrl = html_entity_decode($matches[1]);
        $this->assertStringContainsString('signature=', $imageUrl);
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'inline');
        $this->assertSame(1, count(Storage::disk('private')->allFiles('viewer-pages')));
        Storage::disk('private')->assertExists('lessons/notes.pdf');
    }

    public function test_teacher_can_filter_audit_logs_and_opening_the_page_clears_alerts(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $student = Student::factory()->create();
        AuditLog::factory()->create(['student_id' => $student->id, 'event' => 'suspicious_copy', 'points' => 2]);
        $teacher->notify(new SuspiciousStudentActivity(['summary' => 'تنبيه اختبار']));

        $this->actingAs($teacher, 'web')->get(route('admin.activity', ['event' => 'suspicious_copy']))
            ->assertOk()->assertSee($student->name)->assertSee('نسخ');
        $this->assertSame(0, $teacher->fresh()->unreadNotifications()->count());
    }

    /** @return array{Course, Lesson} */
    private function publishedLesson(): array
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $parent = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        foreach (['part', 'chapter', 'section'] as $type) {
            $parent = $parent->children()->create(['course_id' => $course->id, 'type' => $type, 'title' => ucfirst($type), 'publication_status' => 'published']);
        }
        $lesson = $course->lessons()->create([
            'title' => 'درس مراقب', 'slug' => 'monitored-lesson', 'type' => 'video', 'duration_seconds' => 90,
            'is_published' => true, 'publication_status' => 'published',
        ]);
        $parent->children()->create(['course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published']);

        return [$course, $lesson];
    }
}
