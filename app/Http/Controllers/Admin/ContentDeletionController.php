<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Services\ContentDeletionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContentDeletionController extends Controller
{
    public function grade(Request $request, GradeLevel $gradeLevel, ContentDeletionService $deletion): View
    {
        $this->authorize('courses.manage');

        return $this->confirmation($request, $gradeLevel, $deletion, route('admin.grade-levels.destroy', $gradeLevel), route('admin.grade-levels.index'));
    }

    public function course(Request $request, Course $course, ContentDeletionService $deletion): View
    {
        $this->authorize('delete', $course);

        return $this->confirmation($request, $course, $deletion, route('admin.courses.destroy', $course), route('admin.courses.edit', $course));
    }

    public function lesson(Request $request, Lesson $lesson, ContentDeletionService $deletion): View
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);

        return $this->confirmation($request, $lesson, $deletion, route('admin.lessons.destroy', $lesson), route('admin.lessons.edit', $lesson));
    }

    private function confirmation(Request $request, GradeLevel|Course|Lesson $content, ContentDeletionService $deletion, string $action, string $cancelUrl): View
    {
        $preview = $deletion->preview($content);
        $nonce = bin2hex(random_bytes(32));
        $request->session()->put($deletion->confirmationKey($content), ['nonce' => $nonce, 'fingerprint' => $preview['fingerprint'], 'expires_at' => now()->addMinutes(10)->timestamp]);

        return view('admin.delete-content', compact('preview', 'nonce', 'action', 'cancelUrl'));
    }
}
