<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VideoStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_receives_signed_hls_playlist_and_key_only_for_an_assigned_course(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = $this->createPublishedLesson($course);
        $video = LessonVideo::create([
            'lesson_id' => $lesson->id, 'status' => 'ready', 'output_path' => 'hls/'.$lesson->id,
            'key_path' => 'video-keys/'.$lesson->id.'.key', 'available_resolutions' => [720, 360],
            'enabled_resolutions' => [720], 'duration_seconds' => 120,
        ]);
        Storage::disk('private')->put('hls/'.$lesson->id.'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3000000,RESOLUTION=1280x720\nv720/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360\nv360/index.m3u8\n");
        Storage::disk('private')->put('hls/'.$lesson->id.'/v720/index.m3u8', "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key\"\nsegment_00000.ts\n");
        Storage::disk('private')->put('hls/'.$lesson->id.'/v720/segment_00000.ts', 'encrypted-video-segment');
        Storage::disk('private')->put('video-keys/'.$lesson->id.'.key', str_repeat('k', 16));

        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $token = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($token['token']);

        $viewUrl = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str((string) parse_url($viewUrl, PHP_URL_QUERY), $viewQuery);
        $session = $this->getJson(route('student.video.session', ['video' => $video, 'view' => $viewQuery['view']]))->assertOk()->json();
        $manifest = $this->get($session['manifest_url'])->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $this->assertStringContainsString('hls/v720/index.m3u8', $manifest->getContent());
        $this->assertStringNotContainsString('v360/index.m3u8', $manifest->getContent());
        preg_match('/https?:[^\r\n]+/', $manifest->getContent(), $playlistLink);
        $media = $this->get($playlistLink[0])->assertOk();
        preg_match('/URI="([^"]+)"/', $media->getContent(), $keyLink);
        $this->get($keyLink[1])->assertOk()->assertHeader('Pragma', 'no-cache')->assertContent(str_repeat('k', 16));
        preg_match('/^https?:.+$/m', $media->getContent(), $segmentLink);
        $this->get($segmentLink[0])->assertOk()->assertHeader('Content-Type', 'video/mp2t');

        $unsigned = route('student.video.asset', ['video' => $video, 'student' => $student, 'asset' => 'hls/master.m3u8']);
        $this->get($unsigned)->assertForbidden();
        $expired = URL::temporarySignedRoute('student.video.asset', now()->subMinute(), ['video' => $video->id, 'student' => $student->id, 'asset' => 'hls/master.m3u8']);
        $this->get($expired)->assertForbidden();

        $stranger = Student::factory()->create();
        $strangerToken = app(StudentTokenService::class)->issue($stranger);
        $this->post('/logout');
        $this->loginStudent($strangerToken['token']);
        $this->getJson(route('student.video.session', ['video' => $video, 'view' => $viewQuery['view']]))->assertNotFound();
        $this->get($keyLink[1])->assertNotFound();
    }

    public function test_tus_upload_resumes_at_the_persisted_offset_and_rejects_mime_spoofing(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create();
        $lesson = $this->createPublishedLesson($course);
        $this->actingAs($teacher, 'web');
        $fingerprint = hash('sha256', 'lesson-video-file');
        $metadata = 'filename '.base64_encode('lesson.mp4').',fingerprint '.base64_encode($fingerprint);
        $response = $this->call('POST', route('admin.lessons.video-uploads.start', $lesson), [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_LENGTH' => '16', 'HTTP_UPLOAD_METADATA' => $metadata, 'HTTP_ACCEPT' => 'application/json',
        ]);
        $response->assertCreated()->assertHeader('Tus-Resumable', '1.0.0');
        $uploadUrl = $response->headers->get('Location');

        $this->call('PATCH', $uploadUrl, [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream',
        ], 'not-a-vi')->assertNoContent()->assertHeader('Upload-Offset', '8');
        $this->call('HEAD', $uploadUrl)->assertOk()->assertHeader('Upload-Offset', '8');
        $this->call('PATCH', $uploadUrl, [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_OFFSET' => '8', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], 'deo-data')->assertUnprocessable()->assertJsonValidationErrors('video');
        $uploadUuid = basename(parse_url($uploadUrl, PHP_URL_PATH));
        Storage::disk('private')->assertMissing('video-uploads/'.$uploadUuid.'.part');
    }

    public function test_internal_processing_callback_requires_service_token_and_updates_processing_state(): void
    {
        $course = Course::factory()->create();
        $lesson = $this->createPublishedLesson($course);
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'queued', 'job_id' => '54f13b4e-ff0a-4e2d-a3ba-c05293c78e77']);
        config(['services.video_worker.token' => 'service-secret']);
        $payload = ['video_id' => $video->id, 'lesson_id' => $lesson->id, 'job_id' => $video->job_id, 'status' => 'ready', 'width' => 1920, 'height' => 1080, 'duration_seconds' => 600, 'resolutions' => [1080, 720, 480, 360], 'key_fingerprint' => str_repeat('a', 64)];

        $this->postJson(route('internal.video-processing.update'), $payload)->assertUnauthorized();
        $this->withToken('service-secret')->postJson(route('internal.video-processing.update'), $payload)->assertOk();
        $this->assertSame('ready', $video->fresh()->status);
        $this->assertSame([1080, 720, 480, 360], $video->fresh()->available_resolutions);
        $this->assertSame('ready', $lesson->fresh()->video_status);
    }

    public function test_teacher_can_delete_an_unused_rendition_after_worker_removes_its_private_files(): void
    {
        Storage::fake('private');
        Http::fake(['http://video-worker.test/internal/videos/*/renditions/720' => Http::response(['removed_resolution' => 720], 200)]);
        config(['services.video_worker.url' => 'http://video-worker.test', 'services.video_worker.token' => 'service-secret']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create();
        $lesson = $this->createPublishedLesson($course);
        $video = LessonVideo::create([
            'lesson_id' => $lesson->id, 'status' => 'ready', 'output_path' => 'hls/'.$lesson->id,
            'key_path' => 'video-keys/'.$lesson->id.'.key', 'available_resolutions' => [720, 360], 'enabled_resolutions' => [720],
        ]);

        $this->actingAs($teacher, 'web')->deleteJson(route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 720]))
            ->assertOk()->assertJsonPath('available_resolutions', [360])->assertJsonPath('enabled_resolutions', [360]);
        $this->assertSame([360], $video->fresh()->available_resolutions);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://video-worker.test/internal/videos/'.$video->id.'/renditions/720'
            && $request->hasHeader('Authorization', 'Bearer service-secret')
            && $request['output_path'] === 'hls/'.$lesson->id);
    }

    private function createPublishedLesson(Course $course): Lesson
    {
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        $parent = $root;
        foreach (['part', 'chapter', 'section'] as $type) {
            $parent = $parent->children()->create(['course_id' => $course->id, 'type' => $type, 'title' => ucfirst($type), 'publication_status' => 'published']);
        }
        $lesson = $course->lessons()->create(['title' => 'فيديو الاختبار', 'slug' => 'video-test', 'type' => 'video', 'duration_seconds' => 60, 'is_published' => true, 'publication_status' => 'published']);
        $parent->children()->create(['course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published']);

        return $lesson;
    }
}
