<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoUpload;
use App\Video\VideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResumableVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_large_video_is_persisted_byte_for_byte_across_multiple_requests_and_queued_once(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.url' => 'http://video-worker.test', 'services.video_worker.token' => 'secret']);
        Http::fake(['http://video-worker.test/internal/jobs' => Http::response(['queued' => true], 202)]);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text']);
        $contents = hex2bin('000000206674797069736f6d0000020069736f6d69736f32617663316d703431').str_repeat("\x00\xff\x80\x10", 400000);
        $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'half-hour.mp4', strlen($contents), hash('sha256', $contents));
        $this->actingAs($teacher, 'web');

        for ($offset = 0; $offset < strlen($contents); $offset += 524288) {
            $chunk = substr($contents, $offset, 524288);
            $this->patchChunk($upload, $offset, $chunk)->assertNoContent()->assertHeader('Upload-Offset', (string) ($offset + strlen($chunk)));
        }

        $this->assertSame(hash('sha256', $contents), hash_file('sha256', Storage::disk('private')->path($upload->path)));
        $this->assertSame(strlen($contents), $upload->fresh()->offset);
        $this->assertSame('complete', $upload->fresh()->status);
        $this->assertSame('queued', $lesson->fresh()->video->status);
        Http::assertSentCount(1);
    }

    public function test_reopening_the_same_file_restores_the_existing_partial_session_without_browser_storage(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create();
        $fingerprint = hash('sha256', 'file');
        $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'محاضرة طويلة.mp4', 2000000, $fingerprint);
        $this->actingAs($teacher, 'web');
        $this->patchChunk($upload, 0, str_repeat('a', 524288))->assertNoContent();

        $response = $this->call('POST', route('admin.lessons.video-uploads.start', $lesson), [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_LENGTH' => '2000000', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_UPLOAD_METADATA' => 'filename '.base64_encode('محاضرة طويلة.mp4').',fingerprint '.base64_encode($fingerprint),
        ]);

        $response->assertCreated()->assertJsonPath('offset', 524288)->assertJsonPath('upload_url', route('admin.video-uploads.head', $upload));
        $this->assertSame(1, VideoUpload::count());
        $this->patchChunk($upload, 524288, str_repeat('b', 524288))->assertNoContent()->assertHeader('Upload-Offset', '1048576');
    }

    public function test_a_database_position_left_behind_after_a_disconnect_is_reconciled_before_the_next_chunk(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $upload = app(VideoUploadService::class)->start($teacher, Lesson::factory()->create(), 'video.mp4', 2000000, hash('sha256', 'file'));
        Storage::disk('private')->put($upload->path, str_repeat('a', 524288));
        $this->actingAs($teacher, 'web');

        $this->patchChunk($upload, 524288, str_repeat('b', 524288))->assertNoContent()->assertHeader('Upload-Offset', '1048576');

        $this->assertSame(1048576, $upload->fresh()->offset);
        $this->assertSame(hash('sha256', str_repeat('a', 524288).str_repeat('b', 524288)), hash_file('sha256', Storage::disk('private')->path($upload->path)));
    }

    public function test_an_already_received_chunk_returns_409_and_the_authoritative_offset_without_duplicating_bytes(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $upload = app(VideoUploadService::class)->start($teacher, Lesson::factory()->create(), 'video.mp4', 100, hash('sha256', 'file'));
        $this->actingAs($teacher, 'web');
        $this->patchChunk($upload, 0, 'first')->assertNoContent();

        $this->patchChunk($upload, 0, 'first')->assertConflict()->assertHeader('Upload-Offset', '5');

        $this->call('HEAD', route('admin.video-uploads.head', $upload))->assertOk()->assertHeader('Upload-Offset', '5');
        $this->assertSame('first', Storage::disk('private')->get($upload->path));
        $this->patchChunk($upload, 5, 'second')->assertNoContent()->assertHeader('Upload-Offset', '11');
    }

    public function test_expired_or_missing_partial_files_return_410_and_are_not_silently_recreated_by_head(): void
    {
        Storage::fake('private');
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $upload = app(VideoUploadService::class)->start($teacher, Lesson::factory()->create(), 'video.mp4', 100, hash('sha256', 'file'));
        $this->actingAs($teacher, 'web');
        $this->patchChunk($upload, 0, 'first')->assertNoContent();
        $this->travel(6)->days();
        $this->patchChunk($upload, 5, 'second')->assertNoContent();
        $this->travel(6)->days();
        $this->call('HEAD', route('admin.video-uploads.head', $upload))->assertOk()->assertHeader('Upload-Offset', '11');
        $this->travel(2)->days();

        $this->call('HEAD', route('admin.video-uploads.head', $upload))->assertGone();
        $this->patchChunk($upload, 11, 'third')->assertGone();

        $upload->update(['expires_at' => now()->addDay()]);
        Storage::disk('private')->delete($upload->path);
        $this->call('HEAD', route('admin.video-uploads.head', $upload))->assertGone();
        Storage::disk('private')->assertMissing($upload->path);
    }

    public function test_resume_endpoints_keep_authentication_permissions_and_session_ownership(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $upload = app(VideoUploadService::class)->start($teacher, Lesson::factory()->create(), 'video.mp4', 100, hash('sha256', 'file'));

        $this->call('HEAD', route('admin.video-uploads.head', $upload), [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->call('HEAD', route('admin.video-uploads.head', $upload))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->call('HEAD', route('admin.video-uploads.head', $upload))->assertNotFound();
        $this->patchChunk($upload, 0, 'abc')->assertNotFound();

        $this->assertSame(0, $upload->fresh()->offset);
        $this->assertSame('', Storage::disk('private')->get($upload->path));
    }

    public function test_final_confirmation_recovers_all_bytes_saved_before_processing_was_queued_and_is_idempotent(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        config(['services.video_worker.url' => 'http://video-worker.test', 'services.video_worker.token' => 'secret']);
        Http::fake(['http://video-worker.test/internal/jobs' => Http::response(['queued' => true], 202)]);
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text']);
        $contents = hex2bin('000000206674797069736f6d0000020069736f6d69736f32617663316d703431');
        $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'video.mp4', strlen($contents), hash('sha256', $contents));
        Storage::disk('private')->put($upload->path, $contents);
        $this->actingAs($teacher, 'web');

        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson(route('admin.video-uploads.complete', $upload))->assertOk()->assertJsonPath('status', 'queued');

        $this->assertSame('complete', $upload->fresh()->status);
        $this->assertSame('queued', $lesson->fresh()->video->status);
        $this->assertSame('video', $lesson->fresh()->type);
        Http::assertSentCount(1);
    }

    public function test_final_confirmation_refuses_incomplete_files_and_other_administrators(): void
    {
        Storage::fake('private');
        Http::preventStrayRequests();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $lesson = Lesson::factory()->create(['type' => 'text']);
        $upload = app(VideoUploadService::class)->start($teacher, $lesson, 'video.mp4', 100, hash('sha256', 'file'));
        Storage::disk('private')->put($upload->path, 'part');

        $this->actingAs($teacher, 'web')->postJson(route('admin.video-uploads.complete', $upload))->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->postJson(route('admin.video-uploads.complete', $upload))->assertNotFound();

        $this->assertSame('uploading', $upload->fresh()->status);
        $this->assertNull($lesson->fresh()->video);
        Http::assertNothingSent();
    }

    private function patchChunk(VideoUpload $upload, int $offset, string $contents): TestResponse
    {
        return $this->call('PATCH', route('admin.video-uploads.chunk', $upload), [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], $contents);
    }
}
