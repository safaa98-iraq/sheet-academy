<?php

namespace App\Video;

use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VideoUploadService
{
    private const CHUNK_BYTES = 4_194_304;

    private const ALLOWED_MIMES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/x-msvideo', 'video/3gpp', 'video/mpeg', 'video/mp2t'];

    public function start(User $user, Lesson $lesson, string $filename, int $size, string $fingerprint): VideoUpload
    {
        $safeFilename = basename(str_replace('\\', '/', $filename));
        $existing = VideoUpload::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)
            ->where('lesson_id', $lesson->id)->where('original_name', $safeFilename)->where('expected_size', $size)
            ->where('status', 'uploading')->where('expires_at', '>', now())->latest('id')->first();
        if ($existing !== null && (int) $existing->offset === 0) {
            $this->reconcileOffset($existing);

            return $existing;
        }

        $uuid = (string) Str::uuid();
        $path = 'video-uploads/'.$uuid.'.part';
        $absolutePath = Storage::disk('private')->path($path);
        if (! is_dir(dirname($absolutePath)) && ! mkdir(dirname($absolutePath), 0700, true) && ! is_dir(dirname($absolutePath))) {
            throw new RuntimeException('تعذّر تجهيز مساحة رفع الفيديو.');
        }
        touch($absolutePath);
        chmod($absolutePath, 0600);

        return VideoUpload::query()->create([
            'uuid' => $uuid, 'fingerprint' => $fingerprint, 'lesson_id' => $lesson->id, 'user_id' => $user->id,
            'original_name' => $safeFilename, 'expected_size' => $size,
            'offset' => 0, 'path' => $path, 'status' => 'uploading', 'expires_at' => now()->addDay(),
        ]);
    }

    public function append(VideoUpload $upload, int $offset, string $chunk): int
    {
        if ($upload->status !== 'uploading' || $upload->expires_at->isPast()) {
            throw ValidationException::withMessages(['video' => 'انتهت صلاحية جلسة الرفع. ابدأ رفعاً جديداً.']);
        }
        $chunkSize = strlen($chunk);
        if ($chunkSize < 1 || $chunkSize > self::CHUNK_BYTES || $offset !== (int) $upload->offset || ($offset + $chunkSize) > (int) $upload->expected_size) {
            throw ValidationException::withMessages(['video' => 'بيانات الجزء غير صالحة أو أن موضع الرفع تغيّر.']);
        }

        $handle = fopen(Storage::disk('private')->path($upload->path), 'c+b');
        if ($handle === false) {
            throw new RuntimeException('تعذّر فتح ملف الرفع.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر قفل ملف الرفع.');
            }
            clearstatcache(true, Storage::disk('private')->path($upload->path));
            if ((int) fstat($handle)['size'] !== $offset) {
                throw ValidationException::withMessages(['video' => 'وصل جزء آخر من الفيديو بالتزامن. أعد المحاولة لاستكمال الرفع.']);
            }
            if (fseek($handle, $offset) !== 0 || fwrite($handle, $chunk) !== $chunkSize) {
                throw new RuntimeException('تعذّر حفظ جزء الفيديو.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $upload->update(['offset' => $offset + $chunkSize]);

        return (int) $upload->offset;
    }

    public function reconcileOffset(VideoUpload $upload): int
    {
        if ($upload->status !== 'uploading') {
            return (int) $upload->offset;
        }
        $absolutePath = Storage::disk('private')->path($upload->path);
        $handle = fopen($absolutePath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('ملف الرفع غير موجود. ابدأ الرفع من جديد.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر فحص حالة الرفع.');
            }
            $actualSize = (int) fstat($handle)['size'];
            abort_if($actualSize > (int) $upload->expected_size, 409, 'حجم ملف الرفع غير متطابق.');
            if ($actualSize !== (int) $upload->offset) {
                $upload->update(['offset' => $actualSize]);
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return (int) $upload->offset;
    }

    public function complete(VideoUpload $upload, VideoProcessingGateway $gateway): LessonVideo
    {
        if ($upload->status === 'complete' && $upload->lesson->video !== null) {
            return $upload->lesson->video;
        }
        if ($upload->status !== 'uploading' || (int) $upload->offset !== (int) $upload->expected_size) {
            throw ValidationException::withMessages(['video' => 'لم يكتمل رفع جميع أجزاء الفيديو.']);
        }
        $sourcePath = Storage::disk('private')->path($upload->path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: '';
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            $upload->update(['status' => 'rejected']);
            Storage::disk('private')->delete($upload->path);
            throw ValidationException::withMessages(['video' => 'نوع الملف غير مدعوم. ارفع فيديو صالحاً بصيغة MP4 أو MOV أو WebM أو MKV.']);
        }

        $lesson = $upload->lesson;
        $jobId = (string) Str::uuid();
        $video = LessonVideo::query()->updateOrCreate(['lesson_id' => $lesson->id], [
            'status' => 'queued', 'job_id' => $jobId, 'source_path' => $upload->path, 'source_mime' => $mime, 'source_size' => $upload->expected_size,
            'output_path' => 'hls/'.$lesson->id, 'key_path' => 'video-keys/'.$lesson->id.'.key',
            'enabled_resolutions' => [360, 480, 720, 1080], 'available_resolutions' => [], 'error_message' => null,
        ]);
        $upload->update(['status' => 'complete']);
        $lesson->update(['type' => 'video', 'video_status' => 'queued', 'video_reference' => 'video:'.$video->id, 'available_resolutions' => []]);

        try {
            $gateway->enqueue([
                'job_id' => $jobId, 'video_id' => $video->id, 'lesson_id' => $lesson->id,
                'source_path' => $video->source_path, 'output_path' => $video->output_path, 'key_path' => $video->key_path,
                'enabled_resolutions' => $video->enabled_resolutions,
            ]);
        } catch (Throwable $exception) {
            $video->update(['status' => 'failed', 'error_message' => 'تعذّر إرسال الفيديو إلى قائمة المعالجة.']);
            $lesson->update(['video_status' => 'failed']);
            report($exception);
            throw ValidationException::withMessages(['video' => 'تعذّر الاتصال بخدمة معالجة الفيديو. أعد المحاولة من زر إعادة المعالجة.']);
        }

        return $video->refresh();
    }
}
