<?php

namespace App\Video;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class VideoProcessingGateway
{
    /** @param array<string, mixed> $payload */
    public function enqueue(array $payload): string
    {
        $baseUrl = rtrim((string) config('services.video_worker.url'), '/');
        $token = (string) config('services.video_worker.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('خدمة معالجة الفيديو غير مهيأة.');
        }

        $jobId = (string) ($payload['job_id'] ?? Str::uuid());
        Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(10)->post($baseUrl.'/internal/jobs', [
            ...$payload,
            'job_id' => $jobId,
        ])->throw();

        return $jobId;
    }

    public function deleteRendition(int $videoId, string $outputPath, int $height): void
    {
        $baseUrl = rtrim((string) config('services.video_worker.url'), '/');
        $token = (string) config('services.video_worker.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('خدمة معالجة الفيديو غير مهيأة.');
        }

        Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(30)->delete($baseUrl.'/internal/videos/'.$videoId.'/renditions/'.$height, [
            'output_path' => $outputPath,
        ])->throw();
    }
}
