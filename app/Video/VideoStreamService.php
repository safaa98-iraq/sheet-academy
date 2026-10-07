<?php

namespace App\Video;

use App\Models\LessonVideo;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VideoStreamService
{
    public function rewritePlaylist(LessonVideo $video, Student $student, string $storedPath, string $playlist, string $viewLink): string
    {
        $expiresAt = now()->addMinutes(8);
        $enabled = array_map('intval', $video->enabled_resolutions ?? $video->available_resolutions ?? []);
        if (str_ends_with($storedPath, 'master.m3u8')) {
            $lines = preg_split('/\r?\n/', trim($playlist)) ?: [];
            $rewritten = [];
            for ($index = 0; $index < count($lines); $index++) {
                $line = $lines[$index];
                if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                    $variant = $lines[$index + 1] ?? '';
                    preg_match('/RESOLUTION=\d+x(\d+)/', $line, $match);
                    $height = (int) ($match[1] ?? 0);
                    if (! in_array($height, $enabled, true)) {
                        $index++;

                        continue;
                    }
                    $rewritten[] = $line;
                    $rewritten[] = $this->signedAssetUrl($video, $student, 'hls/'.$variant, $expiresAt, $viewLink);
                    $index++;

                    continue;
                }
                $rewritten[] = $line;
            }

            return implode("\n", $rewritten)."\n";
        }

        $relativeDirectory = dirname(substr($storedPath, strlen($video->output_path) + 1));
        $rewritten = preg_replace_callback('/URI="([^"]+)"/', function (array $match) use ($video, $student, $expiresAt, $viewLink): string {
            if ($match[1] === 'key') {
                $url = URL::temporarySignedRoute('student.video.key', $expiresAt, ['video' => $video->id, 'student' => $student->id, 'view' => $viewLink]);

                return 'URI="'.$url.'"';
            }

            return $match[0];
        }, $playlist) ?? $playlist;

        $lines = preg_split('/\r?\n/', $rewritten) ?: [];
        foreach ($lines as &$line) {
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $line = $this->signedAssetUrl($video, $student, 'hls/'.$relativeDirectory.'/'.$line, $expiresAt, $viewLink);
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    private function signedAssetUrl(LessonVideo $video, Student $student, string $asset, Carbon $expiresAt, string $viewLink): string
    {
        return URL::temporarySignedRoute('student.video.asset', $expiresAt, ['video' => $video->id, 'student' => $student->id, 'asset' => $asset, 'view' => $viewLink]);
    }
}
