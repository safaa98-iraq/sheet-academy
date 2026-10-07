<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\LessonVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoProcessingController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $authorization = (string) $request->bearerToken();
        $expected = (string) config('services.video_worker.token');
        abort_if($expected === '' || $authorization === '' || ! hash_equals($expected, $authorization), 401);
        $data = $request->validate([
            'video_id' => ['required', 'integer'], 'lesson_id' => ['required', 'integer'], 'job_id' => ['required', 'uuid'],
            'status' => ['required', 'in:processing,ready,failed'], 'width' => ['nullable', 'integer', 'min:1', 'max:16384'],
            'height' => ['nullable', 'integer', 'min:1', 'max:16384'], 'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'resolutions' => ['sometimes', 'array'], 'resolutions.*' => ['integer', 'min:1', 'max:1080'],
            'key_fingerprint' => ['nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);
        $video = LessonVideo::query()->whereKey($data['video_id'])->where('lesson_id', $data['lesson_id'])->firstOrFail();
        abort_unless(hash_equals((string) $video->job_id, $data['job_id']), 409);
        $availableResolutions = $data['resolutions'] ?? $video->available_resolutions;
        $enabledResolutions = $video->enabled_resolutions ?? [];
        if ($data['status'] === 'ready') {
            $enabledResolutions = array_values(array_intersect($enabledResolutions, $availableResolutions ?? []));
            if ($enabledResolutions === []) {
                $enabledResolutions = $availableResolutions ?? [];
            }
        }
        $video->update([
            'status' => $data['status'], 'source_width' => $data['width'] ?? $video->source_width,
            'source_height' => $data['height'] ?? $video->source_height, 'duration_seconds' => $data['duration_seconds'] ?? $video->duration_seconds,
            'available_resolutions' => $availableResolutions,
            'enabled_resolutions' => $enabledResolutions,
            'key_fingerprint' => $data['key_fingerprint'] ?? $video->key_fingerprint,
            'processed_at' => $data['status'] === 'ready' ? now() : null,
            'error_message' => $data['status'] === 'failed' ? $data['error'] ?? 'فشلت معالجة الفيديو.' : null,
        ]);
        $lesson = $video->lesson;
        abort_if($lesson === null, 404);
        $lesson->update([
            'video_status' => $data['status'],
            'available_resolutions' => $availableResolutions,
            'duration_seconds' => $data['duration_seconds'] ?? $lesson->duration_seconds,
        ]);

        return response()->json(['ok' => true]);
    }
}
