<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UploadLessonAttachmentsRequest;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LessonAttachmentController extends Controller
{
    public function store(UploadLessonAttachmentsRequest $request, Lesson $lesson): RedirectResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $lesson, &$storedPaths): void {
                foreach ($request->validated('attachments') as $file) {
                    $mimeType = $file->getMimeType();
                    $extension = match ($mimeType) {
                        'application/pdf' => 'pdf',
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        default => abort(422, 'نوع الملف غير مسموح.'),
                    };
                    $directory = 'lessons/'.$lesson->id.'/'.now()->format('Y/m');
                    $filename = Str::uuid().'.'.$extension;
                    $path = Storage::disk('private')->putFileAs($directory, $file, $filename);
                    $storedPaths[] = $path;
                    $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'مرفق';
                    $lesson->attachments()->create([
                        'disk' => 'private',
                        'path' => $path,
                        'original_name' => mb_substr($originalName, 0, 255),
                        'mime_type' => $mimeType,
                        'size_bytes' => $file->getSize(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }

        return back()->with('status', 'تم رفع المرفقات إلى التخزين الخاص.');
    }

    public function student(Request $request, LessonAttachment $attachment): StreamedResponse
    {
        $student = $request->user('student');
        abort_unless(Gate::forUser($student)->allows('viewByStudent', $attachment->lesson), 404);

        return $this->download($attachment);
    }

    public function admin(Request $request, LessonAttachment $attachment): StreamedResponse
    {
        $course = $attachment->lesson?->course;
        abort_if($course === null, 404);
        $this->authorize('view', $course);

        return $this->download($attachment);
    }

    public function destroy(LessonAttachment $attachment): RedirectResponse
    {
        $course = $attachment->lesson?->course;
        abort_if($course === null, 404);
        $this->authorize('update', $course);
        $attachment->delete();

        return back()->with('status', 'تم حذف المرفق حذفاً ناعماً.');
    }

    private function download(LessonAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($attachment->path), 404);

        return Storage::disk('private')->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
