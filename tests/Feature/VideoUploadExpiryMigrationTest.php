<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use App\Video\VideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadExpiryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fix_restores_recent_auto_expired_partial_uploads_and_resumes_the_same_bytes(): void
    {
        Storage::fake('private');
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $upload = app(VideoUploadService::class)->start($teacher, Lesson::factory()->create(), 'lecture.mp4', 100, hash('sha256', 'lecture'));
        Storage::disk('private')->put($upload->path, 'saved');
        DB::table('video_uploads')->where('id', $upload->id)->update(['offset' => 5, 'expires_at' => now(), 'updated_at' => now()]);
        $this->travel(2)->seconds();
        $migration = require database_path('migrations/2026_10_12_070408_remove_automatic_expiry_updates_from_video_uploads.php');

        $migration->up();
        $this->actingAs($teacher, 'web')->call('PATCH', route('admin.video-uploads.chunk', $upload), [], [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0', 'HTTP_UPLOAD_OFFSET' => '5', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], 'next')->assertNoContent()->assertHeader('Upload-Offset', '9');

        $this->assertSame('savednext', Storage::disk('private')->get($upload->path));
        $this->assertTrue($upload->fresh()->expires_at->greaterThan(now()->addDays(6)));
        $expiry = $upload->fresh()->expires_at->toDateTimeString();
        $this->travel(3)->seconds();
        DB::table('video_uploads')->where('id', $upload->id)->update(['original_name' => 'renamed.mp4']);
        $this->assertSame($expiry, $upload->fresh()->expires_at->toDateTimeString());
    }

    public function test_fix_does_not_reactivate_old_completed_or_missing_uploads(): void
    {
        Storage::fake('private');
        $this->freezeTime();
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $service = app(VideoUploadService::class);
        $uploads = [];
        foreach (['old', 'missing', 'completed'] as $kind) {
            $upload = $service->start($teacher, Lesson::factory()->create(), $kind.'.mp4', 100, hash('sha256', $kind));
            DB::table('video_uploads')->where('id', $upload->id)->update(['expires_at' => now()->subDays(8), 'updated_at' => $kind === 'old' ? now()->subDays(8) : now(), 'status' => $kind === 'completed' ? 'complete' : 'uploading']);
            if ($kind === 'missing') {
                Storage::disk('private')->delete($upload->path);
            }
            $uploads[] = $upload;
        }
        $migration = require database_path('migrations/2026_10_12_070408_remove_automatic_expiry_updates_from_video_uploads.php');

        $migration->up();

        foreach ($uploads as $upload) {
            $this->assertTrue($upload->fresh()->expires_at->isPast());
        }
        Storage::disk('private')->assertMissing($uploads[1]->path);
    }
}
