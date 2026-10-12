<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\LessonAttachment;
use App\Services\StudentAuditService;
use App\Services\StudentDocumentPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DocumentViewerController extends Controller
{
    public function show(Request $request, LessonAttachment $attachment, StudentDocumentPageService $pages, StudentAuditService $audit): View
    {
        $student = $request->user('student');
        abort_unless($attachment->lesson !== null && Gate::forUser($student)->allows('viewByStudent', $attachment->lesson), 404);
        $audit->record($student, 'document_opened', $request, ['attachment_id' => $attachment->id]);
        $viewLink = (string) $request->session()->get('active_view_link');
        $pageList = $pages->pages($attachment, $viewLink);

        return view('student.viewer', [
            'attachment' => $attachment, 'documentPages' => $pageList,
            'documentTitle' => $attachment->original_name,
            'refreshUrl' => route('student.documents.refresh', ['attachment' => $attachment, 'view' => $viewLink]),
            'lessonData' => [
                'id' => (string) $attachment->lesson_id, 'url' => route('student.lesson.show', $attachment->lesson),
                'course_title' => $attachment->lesson->course?->title ?? 'المادة التعليمية',
                'course_cover_url' => $attachment->lesson->course?->cover_image,
                'watermark' => app(StudentAuditService::class)->watermarkIdentifier($student),
            ],
        ]);
    }

    public function refresh(Request $request, LessonAttachment $attachment, StudentDocumentPageService $pages): JsonResponse
    {
        abort_unless($attachment->lesson !== null && Gate::forUser($request->user('student'))->allows('viewByStudent', $attachment->lesson), 404);

        return response()->json(['pages' => $pages->pages($attachment, (string) $request->session()->get('active_view_link'))]);
    }

    public function page(Request $request, LessonAttachment $attachment, int $page, StudentDocumentPageService $pages): Response
    {
        abort_unless($attachment->lesson !== null && Gate::forUser($request->user('student'))->allows('viewByStudent', $attachment->lesson), 404);

        $compressed = $request->hasHeader('Accept') && $request->accepts('image/jpeg');
        $bytes = $pages->watermarkedPage($attachment, $page, $request->user('student'), $compressed);

        return response($bytes, 200, [
            'Content-Type' => str_starts_with($bytes, "\xFF\xD8") ? 'image/jpeg' : 'image/png', 'Vary' => 'Accept', 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin',
        ]);
    }
}
