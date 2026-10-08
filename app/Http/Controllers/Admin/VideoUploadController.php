<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartVideoUploadRequest;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\VideoUpload;
use App\Video\VideoProcessingGateway;
use App\Video\VideoStreamService;
use App\Video\VideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VideoUploadController extends Controller
{
    public function previewSession(LessonVideo $video): JsonResponse
    {
        $this->authorizePreview($video);
        $expiresAt = now()->addMinutes(8);

        return response()->json([
            'manifest_url' => URL::temporarySignedRoute('admin.videos.preview-asset', $expiresAt, ['video' => $video->id, 'asset' => 'hls/master.m3u8']),
            'expires_at' => $expiresAt->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function previewAsset(LessonVideo $video, string $asset, VideoStreamService $streams): Response
    {
        $this->authorizePreview($video);
        abort_unless(preg_match('/\A(?:hls\/(?:master\.m3u8|v[1-9]\d{0,3}\/(?:index\.m3u8|segment_\d+\.ts)))\z/D', $asset) === 1, 404);
        $path = $video->output_path.'/'.substr($asset, 4);
        abort_unless(Storage::disk('private')->exists($path), 404);
        if (str_ends_with($path, '.m3u8')) {
            return response($streams->rewritePlaylist($video, null, $path, Storage::disk('private')->get($path), ''), 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file(Storage::disk('private')->path($path), [
            'Content-Type' => 'video/mp2t', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewKey(LessonVideo $video): Response
    {
        $this->authorizePreview($video);
        abort_unless($video->key_path && Storage::disk('private')->exists($video->key_path), 404);

        return response(Storage::disk('private')->get($video->key_path), 200, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
        ]);
    }

    private function authorizePreview(LessonVideo $video): void
    {
        abort_unless($video->status === 'ready' && $video->lesson?->course !== null, 404);
        $this->authorize('view', $video->lesson->course);
    }

    public function start(StartVideoUploadRequest $request, Lesson $lesson, VideoUploadService $uploads): Response
    {
        abort_unless($request->header('Tus-Resumable') === '1.0.0', 412);
        abort_unless($request->header('Upload-Length') !== null, 400, 'حجم الرفع غير محدد.');
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        $data = $request->validated();
        $upload = $uploads->start($request->user('web'), $lesson, $data['filename'], (int) $data['size'], $data['fingerprint']);

        return response('', 201, [
            'Location' => route('admin.video-uploads.head', $upload), 'Tus-Resumable' => '1.0.0', 'Upload-Offset' => (string) $upload->offset,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function options(): Response
    {
        return response('', 204, [
            'Tus-Version' => '1.0.0', 'Tus-Resumable' => '1.0.0', 'Tus-Extension' => 'creation',
            'Tus-Max-Size' => '10737418240', 'Cache-Control' => 'no-store',
        ]);
    }

    public function head(Request $request, VideoUpload $upload, VideoUploadService $uploads): Response
    {
        abort_unless($upload->user_id === $request->user('web')->id, 404);
        $course = $upload->lesson->course;
        abort_if($course === null, 404);
        $this->authorize('update', $course);

        return response('', 200, [
            'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) $upload->expected_size,
            'Upload-Offset' => (string) $uploads->reconcileOffset($upload), 'Upload-Expires' => $upload->expires_at->toRfc7231String(),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function chunk(Request $request, VideoUpload $upload, VideoUploadService $uploads, VideoProcessingGateway $gateway): Response
    {
        abort_unless($request->header('Tus-Resumable') === '1.0.0', 412);
        abort_unless(str_starts_with((string) $request->header('Content-Type'), 'application/offset+octet-stream'), 415);
        abort_unless($upload->user_id === $request->user('web')->id, 404);
        $course = $upload->lesson->course;
        abort_if($course === null, 404);
        $this->authorize('update', $course);
        $offset = filter_var($request->header('Upload-Offset'), FILTER_VALIDATE_INT);
        abort_if($offset === false || $offset === null, 400, 'موضع الرفع غير صالح.');
        $nextOffset = $uploads->append($upload, (int) $offset, $request->getContent());
        if ($nextOffset === (int) $upload->expected_size) {
            $uploads->complete($upload, $gateway);
        }

        return response('', 204, ['Tus-Resumable' => '1.0.0', 'Upload-Offset' => (string) $nextOffset, 'Cache-Control' => 'no-store']);
    }

    public function retry(Request $request, Lesson $lesson, VideoProcessingGateway $gateway): JsonResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        $video = $lesson->video;
        abort_unless($video !== null && $video->status === 'failed', 409);
        abort_unless($video->source_path !== null, 422, 'ملف الفيديو الأصلي غير متوفر. أعد رفع الفيديو.');
        $jobId = (string) Str::uuid();
        $video->update(['status' => 'queued', 'job_id' => $jobId, 'error_message' => null]);
        $lesson->update(['video_status' => 'queued']);
        try {
            $gateway->enqueue(['job_id' => $jobId, 'video_id' => $video->id, 'lesson_id' => $lesson->id,
                'source_path' => $video->source_path, 'output_path' => $video->output_path, 'key_path' => $video->key_path,
                'enabled_resolutions' => $video->enabled_resolutions]);
        } catch (Throwable $exception) {
            $video->update(['status' => 'failed', 'error_message' => 'تعذّر الاتصال بخدمة معالجة الفيديو.']);
            $lesson->update(['video_status' => 'failed']);
            report($exception);
            throw ValidationException::withMessages(['video' => 'تعذّر الاتصال بخدمة معالجة الفيديو. حاول مجدداً.']);
        }

        return response()->json(['status' => 'queued'], 202);
    }

    public function videoStatus(Lesson $lesson): JsonResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('view', $lesson->course);
        $video = $lesson->video;

        return response()->json([
            'status' => $video?->status ?? 'not_uploaded', 'resolutions' => $video?->available_resolutions ?? [],
            'enabled_resolutions' => $video?->enabled_resolutions ?? [], 'duration_seconds' => $video?->duration_seconds,
            'error' => $video?->error_message,
        ]);
    }

    public function resolutions(Request $request, Lesson $lesson): JsonResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        $data = $request->validate([
            'resolutions' => ['required', 'array', 'min:1'],
            'resolutions.*' => ['required', 'integer', 'min:1', 'max:1080'],
        ], [
            'resolutions.required' => 'فعّل دقة واحدة على الأقل للفيديو.',
            'resolutions.min' => 'فعّل دقة واحدة على الأقل للفيديو.',
            'resolutions.*.max' => 'الدقة المحددة غير صالحة.',
        ]);
        $video = $lesson->video;
        abort_unless($video !== null && $video->status === 'ready', 409);
        $available = $video->available_resolutions ?? [];
        abort_if(array_diff($data['resolutions'], $available) !== [], 422, 'حدد دقات متوفرة فقط.');
        $video->update(['enabled_resolutions' => array_values(array_unique($data['resolutions']))]);

        return response()->json(['resolutions' => $video->enabled_resolutions]);
    }

    public function deleteResolution(Request $request, Lesson $lesson, int $resolution, VideoProcessingGateway $gateway): JsonResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        abort_unless($resolution >= 1 && $resolution <= 1080, 404);
        $video = $lesson->video;
        abort_unless($video !== null && $video->status === 'ready', 409);
        $available = array_map('intval', $video->available_resolutions ?? []);
        abort_unless(in_array($resolution, $available, true), 404);
        abort_if(count($available) < 2, 422, 'لا يمكن حذف آخر دقة متوفرة للفيديو.');

        $gateway->deleteRendition((int) $video->id, $video->output_path, $resolution);
        $available = array_values(array_filter($available, fn (int $height): bool => $height !== $resolution));
        $enabled = array_values(array_filter(array_map('intval', $video->enabled_resolutions ?? []), fn (int $height): bool => $height !== $resolution));
        if ($enabled === []) {
            $enabled[] = $available[0];
        }
        $video->update(['available_resolutions' => $available, 'enabled_resolutions' => $enabled]);
        $lesson->update(['available_resolutions' => $available]);

        return response()->json(['available_resolutions' => $available, 'enabled_resolutions' => $enabled]);
    }
}
