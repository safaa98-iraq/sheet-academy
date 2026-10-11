<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_publish_request_saves_private_pdf_and_image_and_exposes_them_to_assigned_student(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'draft', 'published_at' => null]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $response = $this->post(route('admin.courses.lessons.store', $course), [
            'title' => 'محاضرة مع جميع الملفات', 'type' => 'text', 'publication_status' => 'published', 'publish_course' => true,
            'body_html' => '<p onclick="bad()">الشرح</p><script>bad()</script>',
            'attachments' => [UploadedFile::fake()->createWithContent('lecture.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"), UploadedFile::fake()->image('tooth.png')],
        ], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonStructure(['lesson_id', 'edit_url', 'update_url', 'upload_url', 'status_url']);
        $lesson = $course->lessons()->sole();
        $this->assertSame('<p>الشرح</p>', $lesson->body_html);
        $this->assertSame('published', $course->fresh()->publication_status);
        $this->assertSame('published', $course->curriculumRoot->publication_status);
        $this->assertCount(2, $lesson->attachments);
        foreach ($lesson->attachments as $attachment) {
            Storage::disk('private')->assertExists($attachment->path);
            $this->assertFalse(str_starts_with($attachment->path, 'public/'));
        }
        $this->get(route('admin.lessons.index'))->assertOk()->assertSee($lesson->title)->assertSee('lecture.pdf')->assertSee('tooth.png');
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('lecture.pdf')->assertSee('tooth.png');
        $this->get(route('student.course.show', $course))->assertOk()->assertSee('lecture.pdf')->assertSee('tooth.png');
    }

    public function test_invalid_mime_and_unprivileged_author_cannot_create_a_lecture(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create();
        $spoofedPath = tempnam(sys_get_temp_dir(), 'publisher-spoof-');
        file_put_contents($spoofedPath, '<?php echo "bad";');
        $payload = ['title' => 'ملف مزور', 'type' => 'text', 'publication_status' => 'published', 'attachments' => [new UploadedFile($spoofedPath, 'fake.pdf', 'application/pdf', UPLOAD_ERR_OK, true)]];
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->post(route('admin.courses.lessons.store', $course), $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        unlink($spoofedPath);
        $this->actingAs(User::factory()->create(), 'web')->postJson(route('admin.courses.lessons.store', $course), ['title' => 'غير مصرح', 'type' => 'text'])->assertForbidden();
        $this->assertSame(0, $course->lessons()->count());
        $this->assertEmpty(Storage::disk('private')->allFiles());
    }

    public function test_additional_files_are_saved_when_editing_without_replacing_existing_attachments_or_publishing_hidden_course(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'draft', 'published_at' => null]);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        foreach (['one.png', 'two.png'] as $filename) {
            $this->post(route('admin.lessons.update', $lesson), ['_method' => 'PUT', 'title' => $lesson->title, 'type' => 'text', 'publication_status' => 'published', 'attachments' => [UploadedFile::fake()->image($filename)]], ['Accept' => 'application/json'])->assertOk();
        }
        $this->assertSame(2, $lesson->attachments()->count());
        $this->assertFalse($course->fresh()->isVisibleToStudents());
        $outsider = Student::factory()->create();
        $this->loginStudent(app(StudentTokenService::class)->issue($outsider)['token']);
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])->assertNotFound();
    }

    public function test_one_final_save_includes_video_quality_choices_and_rejects_unavailable_resolution(): void
    {
        $lesson = Lesson::factory()->create();
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'available_resolutions' => [360, 720], 'enabled_resolutions' => [360, 720]]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $data = ['title' => 'عنوان ودقات محفوظة معاً', 'type' => 'video', 'publication_status' => 'published', 'enabled_resolutions' => [360], 'duration_seconds' => null, 'position' => null];
        $this->putJson(route('admin.lessons.update', $lesson), $data)->assertOk();
        $this->assertSame($data['title'], $lesson->fresh()->title);
        $this->assertSame([360], $video->fresh()->enabled_resolutions);
        $this->putJson(route('admin.lessons.update', $lesson), [...$data, 'title' => 'تعديل غير صالح', 'enabled_resolutions' => [1080]])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled_resolutions.0');
        $this->assertSame($data['title'], $lesson->fresh()->title);
        $this->assertSame([360], $video->fresh()->enabled_resolutions);
        $this->get(route('admin.lessons.edit', $lesson))->assertOk()->assertDontSee('حفظ الدقات المفعّلة')->assertSee('الدقات المختارة تُحفظ مع زر النشر');
    }

    public function test_scheduling_with_course_publication_makes_both_visible_when_due(): void
    {
        $this->freezeTime();
        $course = Course::factory()->create(['publication_status' => 'draft', 'published_at' => null]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->postJson(route('admin.courses.lessons.store', $course), [
            'title' => 'نشر مجدول مع المادة', 'type' => 'text', 'publication_status' => 'scheduled', 'scheduled_at' => now()->addHour()->toDateTimeString(), 'publish_course' => true, 'notify_students' => true,
        ])->assertCreated();
        $this->assertFalse($course->fresh()->isVisibleToStudents());
        $this->assertSame(0, $student->notifications()->count());
        $this->travel(61)->minutes();
        $this->artisan('app:notify-published-lectures')->assertSuccessful();
        $this->assertTrue($course->fresh()->isVisibleToStudents());
        $this->assertSame(1, $student->notifications()->count());
    }
}
