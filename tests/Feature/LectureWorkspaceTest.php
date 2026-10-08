<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use App\Video\VideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LectureWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_lists_the_selected_material_and_rejects_a_mismatched_grade(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $grade = GradeLevel::factory()->create(['name' => 'المرحلة الأولى']);
        $otherGrade = GradeLevel::factory()->create();
        $course = Course::factory()->create(['grade_level_id' => $grade->id]);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'محاضرة في التشريح']);

        $this->actingAs($teacher, 'web')->get(route('admin.lessons.index', ['grade_level_id' => $grade->id, 'course_id' => $course->id]))
            ->assertOk()->assertSee($lesson->title)->assertSee('إضافة محاضرة')
            ->assertViewHas('selectedCourse', fn (Course $selected): bool => $selected->is($course));
        $this->get(route('admin.lessons.index', ['grade_level_id' => $otherGrade->id, 'course_id' => $course->id]))
            ->assertOk()->assertViewHas('selectedCourse', null)->assertDontSee($lesson->title);
    }

    public function test_teacher_creates_a_lecture_and_continues_in_its_media_editor(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create();
        $this->actingAs($teacher, 'web');

        $response = $this->post(route('admin.courses.lessons.store', $course), [
            'title' => 'المحاضرة الأولى', 'type' => 'text', 'publication_status' => 'published',
            'description' => 'وصف مختصر',
            'body_html' => '<h2>الشرح</h2><p onclick="bad()">نص المحاضرة</p><script>bad()</script>',
        ]);

        $lesson = $course->lessons()->sole();
        $response->assertRedirectToRoute('admin.lessons.edit', $lesson);
        $this->assertSame('published', $lesson->publication_status);
        $this->assertTrue($lesson->is_published);
        $this->assertSame('<h2>الشرح</h2><p>نص المحاضرة</p>', $lesson->body_html);
        $this->assertSame('published', $lesson->curriculumNode->publication_status);
        $this->get(route('admin.lessons.edit', $lesson))->assertOk()->assertSee('الملفات والصور')->assertSee('data-video-upload', false)->assertSee('نص المحاضرة');
    }

    public function test_quick_creation_does_not_place_a_published_lecture_under_draft_sections(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create(['publication_status' => 'published']);
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        $root->children()->create(['course_id' => $course->id, 'type' => 'part', 'title' => 'جزء تحت التحضير', 'publication_status' => 'draft']);
        $this->actingAs($teacher, 'web')->post(route('admin.courses.lessons.store', $course), [
            'title' => 'محاضرة منشورة مباشرة', 'type' => 'text', 'publication_status' => 'published',
        ])->assertRedirect();
        $lesson = $course->lessons()->sole();
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('محاضرة منشورة مباشرة');
    }

    #[TestWith(['draft', true])]
    #[TestWith(['scheduled', false])]
    public function test_quick_creation_keeps_legacy_and_due_scheduled_courses_visible(string $status, bool $legacyPublished): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create([
            'publication_status' => $status,
            'published_at' => $legacyPublished ? now() : null,
            'scheduled_at' => now()->subMinute(),
        ]);
        $this->actingAs($teacher, 'web')->post(route('admin.courses.lessons.store', $course), [
            'title' => 'محاضرة ظاهرة', 'type' => 'text', 'publication_status' => 'published',
        ])->assertRedirect();
        $lesson = $course->lessons()->sole();
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('محاضرة ظاهرة');
    }

    public function test_saving_explanation_preserves_the_processed_video_duration_and_reference(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['video_reference' => 'video:original', 'duration_seconds' => 125]);
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'duration_seconds' => 125]);

        $this->actingAs($teacher, 'web')->putJson(route('admin.lessons.update', $lesson), [
            'title' => 'شرح محدّث', 'type' => 'video', 'publication_status' => 'published',
            'duration_seconds' => 0, 'body_html' => '<p>شرح الفيديو</p>',
        ])->assertOk()->assertJsonPath('message', 'تم حفظ المحاضرة.');

        $lesson->refresh();
        $this->assertSame(125, $lesson->duration_seconds);
        $this->assertSame('video:original', $lesson->video_reference);
        $this->assertSame($video->id, $lesson->video->id);
        $this->assertSame('<p>شرح الفيديو</p>', $lesson->body_html);
    }

    public function test_invalid_autosave_does_not_modify_the_lecture(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['title' => 'عنوان محفوظ']);

        $this->actingAs($teacher, 'web')->putJson(route('admin.lessons.update', $lesson), ['title' => '', 'type' => 'text'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->assertSame('عنوان محفوظ', $lesson->fresh()->title);
    }

    public function test_unprivileged_administrator_cannot_open_or_modify_the_lecture_workspace(): void
    {
        $admin = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($admin, 'web')->get(route('admin.lessons.index'))->assertForbidden();
        $this->get(route('admin.courses.lessons.create', $course))->assertForbidden();
        $this->post(route('admin.courses.lessons.store', $course), ['title' => 'غير مصرح', 'type' => 'text'])->assertForbidden();
        $this->assertSame(0, $course->lessons()->count());
    }

    public function test_teacher_preview_plays_the_uploaded_video_with_signed_private_assets(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text', 'publication_status' => 'draft', 'is_published' => false]);
        $video = LessonVideo::create([
            'lesson_id' => $lesson->id, 'status' => 'ready', 'output_path' => 'hls/'.$lesson->id,
            'key_path' => 'video-keys/'.$lesson->id.'.key', 'available_resolutions' => [360], 'enabled_resolutions' => [360],
        ]);
        Storage::disk('private')->put($video->output_path.'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360\nv360/index.m3u8\n");
        Storage::disk('private')->put($video->output_path.'/v360/index.m3u8', "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key\"\nsegment_00000.ts\n");
        Storage::disk('private')->put($video->output_path.'/v360/segment_00000.ts', 'video-segment');
        Storage::disk('private')->put($video->key_path, str_repeat('k', 16));
        $this->actingAs($teacher, 'web');

        $this->get(route('admin.courses.preview', ['course' => $lesson->course, 'lesson' => $lesson->id]))
            ->assertOk()->assertSee('data-hls-video', false)->assertDontSee('lecture-tooth', false);
        $session = $this->getJson(route('admin.videos.preview-session', $video))->assertOk()->json();
        $manifest = $this->get($session['manifest_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        preg_match('/https?:[^\r\n]+/', $manifest->getContent(), $playlistLink);
        $media = $this->get($playlistLink[0])->assertOk();
        preg_match('/URI="([^"]+)"/', $media->getContent(), $keyLink);
        $this->get($keyLink[1])->assertOk()->assertContent(str_repeat('k', 16));
        preg_match('/^https?:.+$/m', $media->getContent(), $segmentLink);
        $this->get($segmentLink[0])->assertOk()->assertHeader('Content-Type', 'video/mp2t');
        $this->get(route('admin.videos.preview-asset', ['video' => $video, 'asset' => 'hls/master.m3u8']))->assertForbidden();
        $this->actingAs(User::factory()->create(), 'web')->get($session['manifest_url'])->assertForbidden();
    }

    public function test_upload_promotes_a_text_lecture_to_video_and_accepts_an_immediate_worker_callback(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.url' => 'http://video-worker.test', 'services.video_worker.token' => 'service-secret']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text']);
        $contents = hex2bin('000000206674797069736f6d0000020069736f6d69736f32617663316d703431');
        $uploads = app(VideoUploadService::class);
        $upload = $uploads->start($teacher, $lesson, 'lesson.mp4', strlen($contents), hash('sha256', $contents));
        Http::fake(['http://video-worker.test/internal/jobs' => function ($request) {
            $this->withToken('service-secret')->postJson(route('internal.video-processing.update'), [
                'video_id' => $request['video_id'], 'lesson_id' => $request['lesson_id'], 'job_id' => $request['job_id'],
                'status' => 'ready', 'duration_seconds' => 60, 'resolutions' => [360],
            ])->assertOk();

            return Http::response(['job_id' => $request['job_id']], 202);
        }]);

        $this->actingAs($teacher, 'web');
        $this->call('PATCH', route('admin.video-uploads.chunk', $upload), [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
        ], $contents)->assertNoContent();

        $lesson->refresh();
        $this->assertSame('video', $lesson->type);
        $this->assertSame('ready', $lesson->video_status);
        $this->assertSame('ready', $lesson->video->status);
        $this->assertSame('complete', $upload->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_student_sees_a_ready_video_even_when_the_legacy_lecture_type_is_text(): void
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'publication_status' => 'published']);
        LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'available_resolutions' => [360]]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);
        $viewUrl = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str((string) parse_url($viewUrl, PHP_URL_QUERY), $viewQuery);

        $this->get($viewUrl)->assertOk()->assertSee('data-hls-video', false);
        $statusUrl = route('student.lessons.video-status', ['lesson' => $lesson, 'view' => $viewQuery['view']]);
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'ready');
        $stranger = Student::factory()->create();
        $strangerToken = app(StudentTokenService::class)->issue($stranger);
        $this->post('/logout');
        $this->loginStudent($strangerToken['token']);
        $this->getJson($statusUrl)->assertNotFound();
    }

    public function test_processing_lecture_has_a_status_endpoint_for_refreshing_when_ready(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'video']);
        LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'processing']);

        $this->actingAs($teacher, 'web')->get(route('admin.courses.preview', ['course' => $lesson->course, 'lesson' => $lesson->id]))
            ->assertOk()->assertSee('يجري تجهيز الفيديو')->assertSee('data-video-pending-url', false)->assertDontSee('lecture-tooth', false);
    }
}
