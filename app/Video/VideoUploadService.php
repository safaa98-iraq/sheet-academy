<?php

namespace App\Video;

use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\User;
use App\Models\VideoUpload;
use Illuminate\Support\Facades\Log;
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
        if ($existing !== null && Storage::disk('private')->exists($existing->path)) {
            $this->reconcileOffset($existing);
            $existing->update(['expires_at' => now()->addDays(7)]);

            return $existing;
        }

        $uuid = (string) Str::uuid();
        $path = 'video-uploads/'.$uuid.'.part';
        $absolutePath = Storage::disk('private')->path($path);
        if (! is_dir(dirname($absolutePath)) && ! mkdir(dirname($absolutePath), 0700, true) && ! is_dir(dirname($absolutePath))) {
            throw new RuntimeException('تعذّر تجهيز مساحة رفع الفيديو.');
        }
        $freeBytes = disk_free_space(dirname($absolutePath));
        if ($freeBytes !== false && $size + 100 * 1024 ** 2 > $freeBytes) {
            throw ValidationException::withMessages(['video' => 'مساحة التخزين المتاحة لا تكفي لهذا الفيديو. وفّر مساحة في الخدمة أو ارفع ملفاً أصغر.']);
        }
        touch($absolutePath);
        chmod($absolutePath, 0600);

        return VideoUpload::query()->create([
            'uuid' => $uuid, 'fingerprint' => $fingerprint, 'lesson_id' => $lesson->id, 'user_id' => $user->id,
            'original_name' => $safeFilename, 'expected_size' => $size,
            'offset' => 0, 'path' => $path, 'status' => 'uploading', 'expires_at' => now()->addDays(7),
        ]);
    }

    public function append(VideoUpload $upload, int $offset, string $chunk): int
    {
        if ($upload->status !== 'uploading' || $upload->expires_at->isPast()) {
            abort(410, 'انتهت صلاحية جلسة الرفع. اختر الملف نفسه لبدء جلسة جديدة.');
        }
        $chunkSize = strlen($chunk);
        if ($chunkSize < 1 || $chunkSize > self::CHUNK_BYTES || $offset < 0 || ($offset + $chunkSize) > (int) $upload->expected_size) {
            Log::warning('video_upload.invalid_chunk', ['upload_id' => $upload->uuid, 'offset' => $offset, 'bytes' => $chunkSize, 'expected_bytes' => (int) $upload->expected_size]);
            throw ValidationException::withMessages(['video' => 'حجم دفعة الفيديو أو موضعها غير صالح. اختر الملف نفسه لاستئناف الرفع.']);
        }

        abort_unless(Storage::disk('private')->exists($upload->path), 410, 'ملف الرفع المؤقت غير موجود. اختر الملف نفسه لبدء جلسة جديدة.');
        $handle = fopen(Storage::disk('private')->path($upload->path), 'r+b');
        if ($handle === false) {
            throw new RuntimeException('تعذّر فتح ملف الرفع.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر قفل ملف الرفع.');
            }
            clearstatcache(true, Storage::disk('private')->path($upload->path));
            $upload->refresh();
            abort_if($upload->status !== 'uploading' || $upload->expires_at->isPast(), 410, 'انتهت جلسة الرفع.');
            $actualSize = (int) fstat($handle)['size'];
            abort_if($actualSize > (int) $upload->expected_size, 409, 'حجم ملف الرفع غير متطابق.');
            if ($actualSize !== (int) $upload->offset) {
                $upload->update(['offset' => $actualSize]);
            }
            if ($actualSize !== $offset) {
                Log::notice('video_upload.offset_conflict', ['upload_id' => $upload->uuid, 'received_offset' => $offset, 'saved_offset' => $actualSize]);
                abort(409, 'تمت مزامنة موضع الرفع؛ سيُستأنف من آخر دفعة محفوظة.', ['Upload-Offset' => (string) $actualSize, 'Tus-Resumable' => '1.0.0']);
            }
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException('تعذّر حفظ جزء الفيديو.');
            }
            $written = 0;
            while ($written < $chunkSize) {
                $bytes = fwrite($handle, substr($chunk, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('تعذّر حفظ جزء الفيديو؛ تحقق من مساحة التخزين.');
                }
                $written += $bytes;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('تعذّر تثبيت جزء الفيديو على التخزين.');
            }
            // Keep the file and database position under the same lock; HEAD must never overwrite a newer position.
            $upload->update(['offset' => $offset + $chunkSize, 'expires_at' => now()->addDays(7)]);
        } finally {
            fclose($handle);
        }

        return (int) $upload->offset;
    }

    public function reconcileOffset(VideoUpload $upload): int
    {
        abort_if($upload->expires_at->isPast() || $upload->status === 'rejected', 410, 'انتهت صلاحية ملف الرفع المؤقت.');
        if ($upload->status !== 'uploading') {
            return (int) $upload->offset;
        }
        $absolutePath = Storage::disk('private')->path($upload->path);
        abort_unless(is_file($absolutePath), 410, 'ملف الرفع المؤقت غير موجود.');
        $handle = fopen($absolutePath, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('ملف الرفع غير موجود. ابدأ الرفع من جديد.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر فحص حالة الرفع.');
            }
            clearstatcache(true, $absolutePath);
            $upload->refresh();
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
        $path = Storage::disk('private')->path($upload->path);
        if (! is_file($path) && $upload->status === 'complete' && $upload->lesson->video !== null) {
            return $upload->lesson->video;
        }
        abort_unless(is_file($path), 410, 'ملف الرفع المؤقت غير موجود.');
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('تعذّر فتح ملف الفيديو لإكمال الرفع.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر قفل ملف الفيديو لإكمال الرفع.');
            }
            $upload->refresh();

            return $this->queueCompletedUpload($upload, $gateway);
        } finally {
            fclose($handle);
        }
    }

    private function queueCompletedUpload(VideoUpload $upload, VideoProcessingGateway $gateway): LessonVideo
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
            Log::warning('video_upload.unsupported_content', ['upload_id' => $upload->uuid, 'mime' => $mime, 'bytes' => (int) $upload->offset]);
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
