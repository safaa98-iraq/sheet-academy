<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use App\Video\VideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DirectVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_mp4_upload_becomes_ready_without_worker_and_preserves_real_duration_and_bytes(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.mode' => 'direct']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text']);
        $contents = $this->mp4();
        $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'lesson.mp4', strlen($contents), hash('sha256', $contents));
        Storage::disk('private')->put($upload->path, $contents);

        $this->actingAs($teacher, 'web')->postJson(route('admin.video-uploads.complete', $upload))->assertOk()->assertJsonPath('status', 'ready');
        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk()->assertJsonPath('status', 'ready');

        $video = $lesson->fresh()->video;
        $this->assertTrue($video->isDirect());
        $this->assertSame(2, $video->duration_seconds);
        $this->assertSame(64, $video->source_width);
        $this->assertSame(48, $video->source_height);
        $this->assertNull($video->key_path);
        $this->assertSame([], $video->available_resolutions);
        $this->assertSame(2, $lesson->fresh()->duration_seconds);
        $this->assertSame('complete', $upload->fresh()->status);
        $this->assertSame($contents, Storage::disk('private')->get($upload->path));
        Http::assertNothingSent();
    }

    public function test_unsupported_codec_and_truncated_mp4_do_not_become_ready(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.mode' => 'direct']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($teacher, 'web');
        foreach ([str_replace('avc1', 'hvc1', $this->mp4()), substr($this->mp4(), 0, 100)] as $contents) {
            $lesson = Lesson::factory()->create();
            $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'lesson.mp4', strlen($contents), hash('sha256', $contents));
            Storage::disk('private')->put($upload->path, $contents);
            $this->postJson(route('admin.video-uploads.complete', $upload))->assertUnprocessable()->assertJsonValidationErrors('video');
            $this->assertSame('failed', $lesson->fresh()->video->status);
        }
        Http::assertNothingSent();
    }

    public function test_failed_existing_upload_can_be_retried_locally_without_uploading_again(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.mode' => 'direct']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['video_status' => 'failed']);
        $video = $this->directVideo($lesson);
        $video->update(['status' => 'failed', 'output_path' => 'hls/'.$lesson->id]);

        $this->actingAs($teacher, 'web')->postJson(route('admin.lessons.video-retry', $lesson))->assertOk()->assertJsonPath('status', 'ready');

        $this->assertTrue($video->fresh()->isDirect());
        $this->assertSame('ready', $lesson->fresh()->video_status);
        Storage::disk('private')->assertExists($video->source_path);
        Http::assertNothingSent();
    }

    public function test_student_mp4_supports_ranges_and_rejects_unsigned_expired_and_other_assets(): void
    {
        Storage::fake('private');
        $this->freezeTime();
        [$lesson, $video, $student, $session] = $this->studentSession();
        $this->assertSame('mp4', $session['stream_type']);
        $response = $this->get($session['media_url'], ['Range' => 'bytes=0-15'])->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')->assertHeader('Content-Length', '16')
            ->assertHeader('Content-Range', 'bytes 0-15/'.strlen($this->mp4()));
        ob_start();
        $response->baseResponse->sendContent();
        $this->assertSame(substr($this->mp4(), 0, 16), ob_get_clean());
        $this->get($session['media_url'], ['Range' => 'bytes=999999-1000000'])->assertStatus(416);
        $this->get(route('student.video.asset', ['video' => $video, 'student' => $student, 'asset' => 'source.mp4']))->assertForbidden();
        parse_str((string) parse_url($session['media_url'], PHP_URL_QUERY), $query);
        $wrong = URL::temporarySignedRoute('student.video.asset', now()->addMinute(), ['video' => $video, 'student' => $student, 'asset' => 'hls/master.m3u8', 'view' => $query['view']]);
        $this->get($wrong)->assertNotFound();
        $this->travel(9)->minutes();
        $this->get($session['media_url'])->assertForbidden();
    }

    public function test_mp4_is_denied_after_course_access_is_removed_and_when_private_file_is_missing(): void
    {
        Storage::fake('private');
        [$lesson, $video, $student, $session] = $this->studentSession();
        Storage::disk('private')->delete($video->source_path);
        $this->get($session['media_url'])->assertNotFound();
        Storage::disk('private')->put($video->source_path, $this->mp4());
        $student->courses()->detach($lesson->course_id);
        $this->get($session['media_url'])->assertNotFound();
    }

    public function test_rotating_the_view_link_or_logging_out_invalidates_existing_mp4_urls(): void
    {
        Storage::fake('private');
        [$lesson, $video, $student, $session] = $this->studentSession();
        $this->issueStudentViewLink('lesson', $lesson->id);
        $this->get($session['media_url'])->assertNotFound();
        $this->post('/logout');
        $this->getJson($session['media_url'])->assertUnauthorized();
    }

    public function test_student_cannot_reuse_another_students_signed_mp4_url(): void
    {
        Storage::fake('private');
        [$lesson, $video, $student, $session] = $this->studentSession();
        $this->post('/logout');
        $other = Student::factory()->create();
        $other->courses()->attach($lesson->course_id);
        $token = app(StudentTokenService::class)->issue($other);
        $this->loginStudent($token['token']);
        $this->issueStudentViewLink('lesson', $lesson->id);
        $this->get($session['media_url'])->assertNotFound();
    }

    public function test_admin_preview_supports_mp4_but_still_requires_course_permissions(): void
    {
        Storage::fake('private');
        $video = $this->directVideo(Lesson::factory()->create());
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $session = $this->actingAs($teacher, 'web')->getJson(route('admin.videos.preview-session', $video))->assertOk()->json();
        $this->assertSame('mp4', $session['stream_type']);
        $this->get($session['media_url'], ['Range' => 'bytes=0-15'])->assertStatus(206);
        $this->actingAs(User::factory()->create(), 'web')->get($session['media_url'])->assertForbidden();
    }

    /** @return array{Lesson, LessonVideo, Student, array<string, mixed>} */
    private function studentSession(): array
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $parent = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        foreach (['part', 'chapter', 'section'] as $type) {
            $parent = $parent->children()->create(['course_id' => $course->id, 'type' => $type, 'title' => ucfirst($type), 'publication_status' => 'published']);
        }
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'type' => 'video', 'video_status' => 'ready', 'publication_status' => 'published', 'is_published' => true]);
        $parent->children()->create(['course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published']);
        $video = $this->directVideo($lesson);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);
        $viewUrl = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str((string) parse_url($viewUrl, PHP_URL_QUERY), $query);
        $session = $this->getJson(route('student.video.session', ['video' => $video, 'view' => $query['view']]))->assertOk()->json();

        return [$lesson, $video, $student, $session];
    }

    private function directVideo(Lesson $lesson): LessonVideo
    {
        $path = 'video-uploads/54f13b4e-ff0a-4e2d-a3ba-c05293c78e77.part';
        Storage::disk('private')->put($path, $this->mp4());

        return LessonVideo::create([
            'lesson_id' => $lesson->id, 'status' => 'ready', 'source_path' => $path, 'source_mime' => 'video/mp4',
            'source_size' => strlen($this->mp4()), 'output_path' => 'direct/'.$lesson->id, 'duration_seconds' => 2,
            'available_resolutions' => [], 'enabled_resolutions' => [],
        ]);
    }

    private function mp4(): string
    {
        return base64_decode(file_get_contents(__DIR__.'/../Fixtures/direct-video.mp4.b64'), true);
    }
}
