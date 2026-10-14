<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\StudentTokenService;
use App\Video\VideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectVideoRenditionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_360p_copy_keeps_the_720p_original_and_detects_both_real_qualities(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.mode' => 'direct']);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        [$lesson, $video] = $this->video();
        $primary = $video->source_path;
        $this->actingAs($teacher, 'web');
        $upload = $this->variant($lesson, 360);
        Storage::disk('private')->put($upload->path, $this->mp4(360));

        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk()->assertJsonPath('status', 'ready');
        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk();

        $video->refresh();
        $this->assertSame($primary, $video->source_path);
        $this->assertSame([720, 360], $video->available_resolutions);
        $this->assertSame([720, 360], $video->enabled_resolutions);
        $this->assertSame([720, 360], $lesson->fresh()->available_resolutions);
        $this->assertSame('complete', $upload->fresh()->status);
        Storage::disk('private')->assertExists($primary);
        $this->assertSame($this->mp4(360), Storage::disk('private')->get($upload->path));
        Http::assertNothingSent();
    }

    public function test_variant_creation_requires_a_ready_direct_video_and_course_permissions(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video] = $this->video();
        $headers = $this->headers(360);
        $url = route('admin.lessons.video-variants.start', $lesson);
        $this->call('POST', $url, [], [], [], $headers)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->call('POST', $url, [], [], [], $headers)->assertForbidden();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($teacher, 'web');
        $video->update(['status' => 'failed']);
        $this->call('POST', $url, [], [], [], $headers)->assertConflict();
        $video->update(['status' => 'ready', 'output_path' => 'hls/'.$lesson->id]);
        $this->call('POST', $url, [], [], [], $headers)->assertConflict();
        $this->assertSame(0, VideoUpload::count());
    }

    public function test_variant_resume_does_not_reuse_an_original_upload_session(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video] = $this->video();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $file = $this->mp4(360);
        $base = app(VideoUploadService::class)->start($teacher, $lesson, 'quality.mp4', strlen($file), hash('sha256', $file));
        $this->actingAs($teacher, 'web');
        $variant = $this->variant($lesson, 360);
        $resumed = $this->variant($lesson, 360);

        $this->assertNotSame($base->id, $variant->id);
        $this->assertSame($variant->id, $resumed->id);
        $this->assertSame(2, VideoUpload::count());
    }

    public function test_replacing_the_primary_video_during_variant_upload_prevents_attaching_it_to_the_new_lecture(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video] = $this->video();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $upload = $this->variant($lesson, 360);
        Storage::disk('private')->put($upload->path, $this->mp4(360));
        $video->update(['source_path' => 'video-uploads/84f13b4e-ff0a-4e2d-a3ba-c05293c78e77.part']);

        $this->postJson(route('admin.video-uploads.complete', $upload))->assertConflict();

        $this->assertSame([720], $video->fresh()->available_resolutions);
        $this->assertSame('ready', $video->fresh()->status);
    }

    public function test_a_copy_with_a_different_duration_is_rejected_without_breaking_the_existing_video(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video] = $this->video();
        $video->update(['duration_seconds' => 600]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $upload = $this->variant($lesson, 360);
        Storage::disk('private')->put($upload->path, $this->mp4(360));

        $this->postJson(route('admin.video-uploads.complete', $upload))->assertUnprocessable()->assertJsonValidationErrors('video');

        $this->assertSame('ready', $video->fresh()->status);
        $this->assertSame([720], $video->fresh()->available_resolutions);
    }

    public function test_each_student_quality_is_signed_and_disabled_quality_is_revoked_from_existing_urls(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video, $teacher] = $this->twoQualities();
        $student = Student::factory()->create();
        $student->courses()->attach($lesson->course_id);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $session = $this->getJson(route('student.video.session', ['video' => $video, 'view' => $query['view']]))->assertOk()->json();
        $this->assertSame([720, 360], array_map('intval', array_keys($session['rendition_urls'])));
        foreach ($session['rendition_urls'] as $height => $mediaUrl) {
            $response = $this->get($mediaUrl, ['Range' => 'bytes=0-15'])->assertStatus(206);
            $this->assertSame(hash('sha256', $this->mp4((int) $height)), hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
            ob_start();
            $response->baseResponse->sendContent();
            $this->assertSame(substr($this->mp4((int) $height), 0, 16), ob_get_clean());
        }
        $this->actingAs($teacher, 'web')->putJson(route('admin.lessons.video-resolutions', $lesson), ['resolutions' => [360]])->assertOk();
        $this->get($session['rendition_urls']['720'])->assertNotFound();
        $this->get($session['rendition_urls']['360'])->assertOk();
        $this->getJson(route('student.video.session', ['video' => $video, 'view' => $query['view']]))->assertOk()->assertJsonMissingPath('rendition_urls.720');
        $this->putJson('/player-preferences', ['video_quality' => '360p'])->assertOk()->assertJsonPath('video_quality', '360p');
    }

    public function test_deleting_the_primary_quality_keeps_another_copy_playable_and_refuses_deleting_the_last_one(): void
    {
        Storage::fake('private');
        config(['services.video_worker.mode' => 'direct']);
        [$lesson, $video, $teacher] = $this->twoQualities();
        $original = $video->source_path;
        $this->actingAs($teacher, 'web')->deleteJson(route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 720]))
            ->assertOk()->assertJsonPath('available_resolutions', [360]);
        Storage::disk('private')->assertMissing($original);
        $video->refresh();
        $this->assertNotSame($original, $video->source_path);
        Storage::disk('private')->assertExists($video->source_path);
        $session = $this->getJson(route('admin.videos.preview-session', $video))->assertOk()->json();
        $this->get($session['rendition_urls']['360'])->assertOk();
        $this->deleteJson(route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 360]))->assertUnprocessable();
        Storage::disk('private')->assertExists($video->source_path);
    }

    /** @return array{Lesson, LessonVideo, User} */
    private function twoQualities(): array
    {
        [$lesson, $video] = $this->video();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($teacher, 'web');
        $upload = $this->variant($lesson, 360);
        Storage::disk('private')->put($upload->path, $this->mp4(360));
        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk();

        return [$lesson, $video->fresh(), $teacher];
    }

    private function variant(Lesson $lesson, int $height): VideoUpload
    {
        $response = $this->call('POST', route('admin.lessons.video-variants.start', $lesson), [], [], [], $this->headers($height))->assertCreated();

        return VideoUpload::query()->where('uuid', basename(parse_url($response->json('upload_url'), PHP_URL_PATH)))->firstOrFail();
    }

    /** @return array<string, string> */
    private function headers(int $height): array
    {
        $file = $this->mp4($height);

        return [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_LENGTH' => (string) strlen($file), 'HTTP_ACCEPT' => 'application/json',
            'HTTP_UPLOAD_METADATA' => 'filename '.base64_encode('quality.mp4').',fingerprint '.base64_encode(hash('sha256', $file)),
        ];
    }

    /** @return array{Lesson, LessonVideo} */
    private function video(): array
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $parent = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        foreach (['part', 'chapter', 'section'] as $type) {
            $parent = $parent->children()->create(['course_id' => $course->id, 'type' => $type, 'title' => ucfirst($type), 'publication_status' => 'published']);
        }
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'type' => 'video', 'video_status' => 'ready', 'duration_seconds' => 2, 'publication_status' => 'published', 'is_published' => true]);
        $parent->children()->create(['course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published']);
        $path = 'video-uploads/54f13b4e-ff0a-4e2d-a3ba-c05293c78e77.part';
        Storage::disk('private')->put($path, $this->mp4(720));
        $video = LessonVideo::create([
            'lesson_id' => $lesson->id, 'status' => 'ready', 'source_path' => $path, 'source_mime' => 'video/mp4',
            'source_size' => strlen($this->mp4(720)), 'source_width' => 1280, 'source_height' => 720,
            'duration_seconds' => 2, 'output_path' => 'direct/'.$lesson->id, 'available_resolutions' => [720], 'enabled_resolutions' => [720],
        ]);

        return [$lesson, $video];
    }

    private function mp4(int $height): string
    {
        return base64_decode(file_get_contents(__DIR__.'/../Fixtures/quality-'.$height.'.mp4.b64'), true);
    }
}
