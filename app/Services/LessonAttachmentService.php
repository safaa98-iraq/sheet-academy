<?php

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LessonAttachmentService
{
    /** @param array<int, UploadedFile> $files */
    public function store(Lesson $lesson, array $files): void
    {
        $storedPaths = [];
        try {
            DB::transaction(function () use ($lesson, $files, &$storedPaths): void {
                foreach ($files as $file) {
                    $mime = $file->getMimeType();
                    $extension = match ($mime) {
                        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                        default => abort(422, 'نوع الملف الحقيقي غير مسموح.'),
                    };
                    $path = Storage::disk('private')->putFileAs('lessons/'.$lesson->id.'/'.now()->format('Y/m'), $file, Str::uuid().'.'.$extension);
                    if ($path === false) {
                        throw new \RuntimeException('تعذّر حفظ المرفق. حاول مجدداً.');
                    }
                    $storedPaths[] = $path;
                    $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'مرفق';
                    $lesson->attachments()->create(['disk' => 'private', 'path' => $path, 'original_name' => mb_substr($name, 0, 255), 'mime_type' => $mime, 'size_bytes' => $file->getSize()]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($storedPaths);
            throw $exception;
        }
    }
}
