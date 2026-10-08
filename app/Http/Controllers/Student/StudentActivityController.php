<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\StudentDevice;
use App\Services\StudentAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentActivityController extends Controller
{
    public function store(Request $request, StudentAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:watch_started,watch_heartbeat,suspicious_devtools,suspicious_copy,suspicious_print,suspicious_watermark'],
            'lesson_id' => ['nullable', 'integer', 'min:1'],
            'view' => ['nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ], ['event.in' => 'نوع النشاط غير مدعوم.']);
        $student = $request->user('student');
        $device = StudentDevice::query()->whereKey($request->session()->get('student_device_id'))
            ->where('student_id', $student->id)->whereNull('revoked_at')->firstOrFail();
        if (isset($data['view'])) {
            abort_unless($device->view_link_hash !== null && hash_equals($device->view_link_hash, hash('sha256', $data['view'])), 404);
        } elseif (in_array($data['event'], ['watch_started', 'watch_heartbeat', 'suspicious_watermark'], true)) {
            abort(404);
        }
        $lessonId = isset($data['lesson_id']) ? (int) $data['lesson_id'] : null;
        if ($lessonId !== null) {
            $lesson = Lesson::query()->findOrFail($lessonId);
            abort_unless(Gate::forUser($student)->allows('viewByStudent', $lesson), 404);
        }
        $audit->record($student, $data['event'], $request, array_filter(['lesson_id' => $lessonId]), $device);
        if (in_array($data['event'], ['suspicious_watermark', 'suspicious_devtools'], true)) {
            $device->forceFill(['view_link_hash' => null])->save();
            $request->session()->forget('active_view_link');
        }
        if ($student->fresh()->status !== 'active') {
            return response()->json(['message' => 'أُوقفت الجلسة بسبب تجاوز حد المخالفات.'], 423);
        }

        return response()->json(['recorded' => true]);
    }
}
