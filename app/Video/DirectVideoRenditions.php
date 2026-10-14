<?php

namespace App\Video;

use App\Models\LessonVideo;
use App\Models\VideoUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DirectVideoRenditions
{
    /** @return array<int, array{path: string, width: int, height: int, duration_seconds: int}> */
    public function sources(LessonVideo $video): array
    {
        $disk = Storage::disk('private');
        $index = $video->output_path.'/renditions.json';
        if ($video->isDirect() && $disk->exists($index)) {
            $data = json_decode($disk->get($index), true);
            if (is_array($data) && ($data['source'] ?? null) === $video->source_path && is_array($data['renditions'] ?? null)) {
                return $data['renditions'];
            }
        }
        $height = min((int) $video->source_width, (int) $video->source_height);

        return $height > 0 ? [$height => [
            'path' => $video->source_path, 'width' => (int) $video->source_width, 'height' => (int) $video->source_height,
            'duration_seconds' => (int) $video->duration_seconds,
        ]] : [];
    }

    public function attach(VideoUpload $upload): LessonVideo
    {
        $disk = Storage::disk('private');
        abort_unless($disk->exists($upload->path.'.variant'), 409, 'بيانات جلسة الدقة غير موجودة. ابدأ رفع النسخة من جديد.');
        $marker = json_decode($disk->get($upload->path.'.variant'), true);
        $path = $disk->path($upload->path);
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'video/mp4') {
            throw ValidationException::withMessages(['video' => 'ارفع نسخة MP4 بترميز H.264 وصوت AAC.']);
        }
        $metadata = app(Mp4Metadata::class)->read($path);

        return DB::transaction(function () use ($upload, $marker, $metadata): LessonVideo {
            $current = VideoUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $video = LessonVideo::query()->where('lesson_id', $upload->lesson_id)->lockForUpdate()->firstOrFail();
            abort_unless($video->isDirect() && $video->status === 'ready' && is_array($marker) && ($marker['source'] ?? null) === $video->source_path, 409, 'تغيّر الفيديو الأساسي. ابدأ رفع نسخة الدقة من جديد.');
            if ($current->status === 'complete') {
                return $video;
            }
            if (abs($metadata['duration_seconds'] - (int) $video->duration_seconds) > 2) {
                throw ValidationException::withMessages(['video' => 'مدة هذه النسخة تختلف عن الفيديو الأساسي. ارفع نسخة من المحاضرة نفسها.']);
            }
            $height = min($metadata['width'], $metadata['height']);
            $sources = $this->sources($video);
            $sources[$height] = ['path' => $upload->path, ...$metadata];
            if ($height === min((int) $video->source_width, (int) $video->source_height)) {
                $video->fill(['source_path' => $upload->path, 'source_width' => $metadata['width'],
                    'source_height' => $metadata['height'], 'source_size' => $upload->expected_size]);
            }
            $this->write($video, $sources);
            $available = array_map('intval', array_keys($sources));
            rsort($available);
            $enabled = array_values(array_unique([...($video->enabled_resolutions ?: array_keys($sources)), $height]));
            rsort($enabled);
            $video->update(['available_resolutions' => $available, 'enabled_resolutions' => $enabled]);
            $video->lesson->update(['available_resolutions' => $available]);
            $current->update(['status' => 'complete']);

            return $video;
        });
    }

    public function remove(LessonVideo $video, int $height): void
    {
        $path = DB::transaction(function () use ($video, $height): string {
            $current = LessonVideo::query()->whereKey($video->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->isDirect() && $current->status === 'ready', 409);
            $sources = $this->sources($current);
            abort_unless(isset($sources[$height]), 404);
            abort_if(count($sources) < 2, 422, 'لا يمكن حذف آخر دقة متوفرة للفيديو.');
            $removedPath = $sources[$height]['path'];
            unset($sources[$height]);
            $available = array_map('intval', array_keys($sources));
            rsort($available);
            $enabled = array_values(array_intersect(array_map('intval', $current->enabled_resolutions ?? []), $available));
            if ($enabled === []) {
                $enabled = [$available[0]];
            }
            $replacement = $sources[$available[0]];
            if ($removedPath === $current->source_path) {
                $current->source_path = $replacement['path'];
                $current->source_width = $replacement['width'];
                $current->source_height = $replacement['height'];
                $current->source_size = Storage::disk('private')->size($replacement['path']);
            }
            $this->write($current, $sources);
            $current->fill(['available_resolutions' => $available, 'enabled_resolutions' => $enabled])->save();
            $current->lesson->update(['available_resolutions' => $available]);

            return $removedPath;
        });
        if (preg_match('/\Avideo-uploads\/(?:variants\/)?[a-f0-9-]{36}\.part\z/i', $path) === 1) {
            Storage::disk('private')->delete($path);
        }
    }

    /** @param array<int, array{path: string, width: int, height: int, duration_seconds: int}> $sources */
    private function write(LessonVideo $video, array $sources): void
    {
        $disk = Storage::disk('private');
        $index = $video->output_path.'/renditions.json';
        $temporary = $index.'.'.Str::uuid().'.tmp';
        $disk->put($temporary, json_encode(['source' => $video->source_path, 'renditions' => $sources], JSON_THROW_ON_ERROR));
        if (! rename($disk->path($temporary), $disk->path($index))) {
            $disk->delete($temporary);
            throw new RuntimeException('تعذّر حفظ قائمة دقات الفيديو.');
        }
    }
}
