<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Support\LearningUi;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $student = $request->user('student')->load(['studyGradeLevel', 'gradeLevel', 'preference']);
        $courses = $student->accessibleCourses()->visibleForStudents()->with(['gradeLevel', 'lessons' => fn ($query) => $query->visibleForStudents()->with([
            'progress' => fn ($progress) => $progress->where('student_id', $student->id), 'attachments', 'video',
        ])])->orderBy('position')->get();
        $cards = $courses->map(fn (Course $course): array => LearningUi::course($course, $student));
        $lessons = $cards->flatMap(fn (array $course): array => $course['lessons']);
        $latestLesson = $lessons->filter(fn (array $lesson): bool => $lesson['position_seconds'] > 0 && $lesson['progress_updated_at'] !== null)
            ->sortByDesc('progress_updated_at')->first();
        $device = $student->devices()->whereKey($request->session()->get('student_device_id'))->first();
        $token = $student->tokens()->whereKey($request->session()->get('student_token_id'))->first();

        return view('student.account', [
            'student' => $student, 'currentDevice' => $device, 'currentToken' => $token,
            'deviceLabel' => $this->deviceLabel($device?->user_agent),
            'accountCourses' => $cards->map(fn (array $course): array => [
                'title' => $course['title'], 'url' => $course['url'], 'percentage' => $course['percentage'],
                'completed' => collect($course['lessons'])->where('completed', true)->count(), 'total' => count($course['lessons']),
            ]),
            'accountTotals' => ['courses' => $cards->count(), 'completed' => $lessons->where('completed', true)->count(), 'minutes' => (int) floor($lessons->sum('watched_seconds') / 60)],
            'resumeLesson' => $latestLesson === null ? null : ['title' => $latestLesson['title'], 'url' => $latestLesson['url'], 'position' => $latestLesson['position_seconds']],
        ]);
    }

    private function deviceLabel(?string $agent): string
    {
        $browser = 'المتصفح الحالي';
        foreach (['Edg/' => 'إيدج', 'FxiOS/' => 'فايرفوكس', 'Firefox/' => 'فايرفوكس', 'CriOS/' => 'كروم', 'Chrome/' => 'كروم', 'Safari/' => 'سفاري'] as $match => $label) {
            if (str_contains($agent ?? '', $match)) {
                $browser = $label;
                break;
            }
        }
        foreach (['Android' => 'أندرويد', 'iPad' => 'آيباد', 'iPhone' => 'آيفون', 'Windows' => 'ويندوز', 'Macintosh' => 'ماك', 'Linux' => 'لينكس'] as $match => $label) {
            if (str_contains($agent ?? '', $match)) {
                return $browser.' · '.$label;
            }
        }

        return $browser;
    }
}
