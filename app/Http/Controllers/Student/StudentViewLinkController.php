<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\StudentDevice;
use App\Services\StudentAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentViewLinkController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'string', 'in:lesson,document'],
            'target_id' => ['required', 'integer', 'min:1'],
        ], ['target_type.in' => 'نوع صفحة المشاهدة غير صالح.']);
        if ($data['target_type'] === 'lesson') {
            $lesson = Lesson::query()->findOrFail($data['target_id']);
            abort_unless(Gate::forUser($request->user('student'))->allows('viewByStudent', $lesson), 404);
            $url = route('student.lesson.show', $lesson);
        } else {
            $attachment = LessonAttachment::query()->with('lesson')->findOrFail($data['target_id']);
            abort_unless($attachment->lesson !== null && Gate::forUser($request->user('student'))->allows('viewByStudent', $attachment->lesson), 404);
            abort_unless(in_array($attachment->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 404);
            $url = route('student.documents.show', $attachment);
        }
        $device = StudentDevice::query()->whereKey($request->session()->get('student_device_id'))
            ->where('student_id', $request->user('student')->id)->whereNull('revoked_at')->firstOrFail();
        $viewLink = bin2hex(random_bytes(32));
        $device->forceFill(['view_link_hash' => hash('sha256', $viewLink)])->save();
        $request->session()->put('active_view_link', $viewLink);
        $separator = str_contains($url, '?') ? '&' : '?';

        return response()->json(['url' => $url.$separator.'view='.$viewLink]);
    }

    public function close(Request $request): JsonResponse
    {
        $viewLink = (string) $request->input('view');
        $device = StudentDevice::query()->whereKey($request->session()->get('student_device_id'))
            ->where('student_id', $request->user('student')->id)->whereNull('revoked_at')->first();
        if ($device !== null && $viewLink !== '' && $device->view_link_hash !== null
            && hash_equals($device->view_link_hash, hash('sha256', $viewLink))) {
            $device->forceFill(['view_link_hash' => null])->save();
            app(StudentAuditService::class)->record($request->user('student'), 'view_exited', $request, [], $device);
            if (hash_equals((string) $request->session()->get('active_view_link'), $viewLink)) {
                $request->session()->forget('active_view_link');
            }
        }

        return response()->json(['closed' => true]);
    }
}
