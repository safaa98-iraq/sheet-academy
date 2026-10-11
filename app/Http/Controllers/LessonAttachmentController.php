<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UploadLessonAttachmentsRequest;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Services\LessonAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonAttachmentController extends Controller
{
    public function store(UploadLessonAttachmentsRequest $request, Lesson $lesson): RedirectResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        app(LessonAttachmentService::class)->store($lesson, $request->validated('attachments'));

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
