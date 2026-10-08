<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentProgressController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'status' => ['nullable', 'string', 'in:active,frozen,suspended'],
            'sort' => ['nullable', 'string', 'in:recent,progress,name,time'],
        ], ['q.max' => 'عبارة البحث طويلة جداً.']);
        $students = $this->studentQuery($filters)
            ->withCount(['progress as completed_lessons_count' => fn (Builder $query) => $this->scopeProgress($query, $filters)->whereNotNull('completed_at')])
            ->withSum(['progress as watched_seconds_total' => fn (Builder $query) => $this->scopeProgress($query, $filters)], 'watched_seconds')
            ->withMax(['progress as last_learning_at' => fn (Builder $query) => $this->scopeProgress($query, $filters)], 'updated_at')
            ->when($filters['sort'] ?? 'recent', function (Builder $query, string $sort): void {
                if ($sort === 'progress') {
                    $query->orderByRaw('CASE WHEN available_duration_seconds = 0 THEN 0 ELSE progress_score * 1.0 / available_duration_seconds END DESC');
                } else {
                    $query->orderBy(match ($sort) {
                        'name' => 'students.name', 'time' => 'watched_seconds_total', default => 'last_learning_at',
                    }, $sort === 'name' ? 'asc' : 'desc');
                }
            })
            ->paginate(25)->withQueryString();
        $students->getCollection()->each(function (Student $student): void {
            $student->setAttribute('progress_percent', $student->available_duration_seconds > 0
                ? min(100, (int) round($student->progress_score / $student->available_duration_seconds * 100))
                : 0);
        });
        $stats = Cache::remember('admin.student-progress.stats.v1', now()->addMinutes(2), fn (): array => $this->statistics());
        $courses = Course::query()->orderBy('title')->get(['id', 'title']);

        return view('admin.student-progress.index', compact('students', 'filters', 'courses', 'stats'));
    }

    public function show(Student $student): View
    {
        $progress = $student->progress()->with(['lesson.course'])->latest('updated_at')->paginate(50);
        $lastLogin = AuditLog::query()->where('student_id', $student->id)->where('event', 'login_succeeded')->latest()->value('created_at');
        $totals = $student->progress()->selectRaw('COUNT(*) as lessons_started, SUM(watched_seconds) as watched_seconds, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as lessons_completed')->first();

        return view('admin.student-progress.show', [
            'student' => $student->load(['devices' => fn ($query) => $query->latest('last_seen_at')]),
            'progress' => $progress, 'totals' => $totals, 'lastLogin' => $lastLogin,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'status' => ['nullable', 'string', 'in:active,frozen,suspended'],
        ]);

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['الطالب', 'البريد', 'الحالة', 'مواد مسجلة', 'دروس مكتملة', 'نسبة الإكمال', 'وقت المشاهدة بالدقائق', 'آخر نشاط'], ',', '"', '\\');
            foreach ($this->exportQuery($filters)->cursor() as $student) {
                fputcsv($output, [
                    $this->csvText($student->name), $this->csvText($student->email), $student->status, $student->courses_count,
                    $student->completed_lessons_count,
                    $student->available_duration_seconds > 0 ? (int) round($student->progress_score / $student->available_duration_seconds * 100) : 0,
                    (int) round((int) $student->watched_seconds_total / 60), $student->last_learning_at,
                ], ',', '"', '\\');
            }
            fclose($output);
        }, 'student-progress.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvText(?string $text): string
    {
        $text ??= '';

        return preg_match('/\A[\x00-\x20]*[=+\-@]/', $text) === 1 ? "'".$text : $text;
    }

    public function printable(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'status' => ['nullable', 'string', 'in:active,frozen,suspended'],
        ]);
        $students = $this->exportQuery($filters)->get();

        return view('admin.student-progress.print', compact('students'));
    }

    /** @param array<string, mixed> $filters */
    private function studentQuery(array $filters): Builder
    {
        $availableCourses = DB::table('courses')->whereNull('courses.deleted_at')->where(function ($query): void {
            $query->whereColumn('courses.grade_level_id', 'students.grade_level_id')
                ->orWhere(function ($specific): void {
                    $specific->whereNull('students.grade_level_id')->whereExists(function ($enrollment): void {
                        $enrollment->selectRaw('1')->from('course_student')->whereColumn('course_student.course_id', 'courses.id')->whereColumn('course_student.student_id', 'students.id');
                    });
                });
        });
        $availableLessons = (clone $availableCourses)->join('lessons', 'lessons.course_id', '=', 'courses.id')
            ->whereNull('courses.deleted_at')->whereNull('lessons.deleted_at')
            ->where(function ($query): void {
                $query->where('courses.publication_status', 'published')
                    ->orWhere(fn ($scheduled) => $scheduled->where('courses.publication_status', 'scheduled')->where('courses.scheduled_at', '<=', now()))
                    ->orWhere(fn ($draft) => $draft->where('courses.publication_status', 'draft')->whereNotNull('courses.published_at'));
            })
            ->where(function ($query): void {
                $query->where('lessons.publication_status', 'published')
                    ->orWhere(fn ($scheduled) => $scheduled->where('lessons.publication_status', 'scheduled')->where('lessons.scheduled_at', '<=', now()))
                    ->orWhere(fn ($draft) => $draft->where('lessons.publication_status', 'draft')->where('lessons.is_published', true));
            })
            ->when($filters['course_id'] ?? null, fn ($query, int $courseId) => $query->where('courses.id', $courseId))
            ->selectRaw('COUNT(lessons.id)');
        $availableDuration = (clone $availableLessons)->select(DB::raw('COALESCE(SUM(lessons.duration_seconds), 0)'));
        $progressScore = DB::table('lesson_progress')->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->whereColumn('lesson_progress.student_id', 'students.id')->whereNull('lessons.deleted_at')
            ->when($filters['course_id'] ?? null, fn ($query, int $courseId) => $query->where('lessons.course_id', $courseId))
            ->selectRaw('COALESCE(SUM(CASE WHEN lesson_progress.completed_at IS NOT NULL THEN lessons.duration_seconds ELSE lesson_progress.watched_seconds END), 0)');
        $lastProgress = DB::table('lesson_progress')->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->whereColumn('lesson_progress.student_id', 'students.id')->whereNull('lessons.deleted_at')->orderByDesc('lesson_progress.updated_at')->limit(1);

        return Student::query()->select('students.*')->selectSub($availableLessons, 'available_lessons_count')
            ->selectSub($availableDuration, 'available_duration_seconds')->selectSub($progressScore, 'progress_score')
            ->selectSub((clone $lastProgress)->select('lessons.title'), 'last_lesson_title')
            ->selectSub((clone $lastProgress)->select('lesson_progress.last_position_seconds'), 'last_position_seconds')
            ->selectSub((clone $availableCourses)->selectRaw('COUNT(*)'), 'courses_count')
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $query->where(fn (Builder $students) => $students->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['course_id'] ?? null, fn (Builder $query, int $courseId) => $query->whereExists((clone $availableCourses)->where('courses.id', $courseId)->selectRaw('1')));
    }

    /** @param array<string, mixed> $filters */
    private function scopeProgress(Builder $query, array $filters): Builder
    {
        return $query->when($filters['course_id'] ?? null, fn (Builder $progress, int $courseId) => $progress->whereHas('lesson', fn (Builder $lesson) => $lesson->where('course_id', $courseId)));
    }

    /** @param array<string, mixed> $filters */
    private function exportQuery(array $filters): Builder
    {
        return $this->studentQuery($filters)
            ->withCount(['progress as completed_lessons_count' => fn (Builder $query) => $this->scopeProgress($query, $filters)->whereNotNull('completed_at')])
            ->withSum(['progress as watched_seconds_total' => fn (Builder $query) => $this->scopeProgress($query, $filters)], 'watched_seconds')
            ->withMax('progress as last_learning_at', 'updated_at')->orderBy('students.name');
    }

    /** @return array<string, mixed> */
    private function statistics(): array
    {
        $progressSummary = LessonProgress::query()->selectRaw('COUNT(*) as started, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed')->first();
        $mostWatched = DB::table('lesson_progress')->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->whereNull('lessons.deleted_at')->where('lesson_progress.watched_seconds', '>', 0)
            ->select('lessons.id', 'lessons.title', DB::raw('SUM(lesson_progress.watched_seconds) as watched_seconds'))
            ->groupBy('lessons.id', 'lessons.title')->orderByDesc('watched_seconds')->limit(5)->get();
        $dropOff = DB::table('lesson_progress')->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->whereNull('lessons.deleted_at')->whereNull('lesson_progress.completed_at')->where('lesson_progress.watched_seconds', '>', 0)
            ->select('lessons.id', 'lessons.title', DB::raw('COUNT(*) as students_count'), DB::raw('AVG(lesson_progress.last_position_seconds) as average_position'))
            ->groupBy('lessons.id', 'lessons.title')->orderByDesc('students_count')->limit(5)->get();

        return [
            'active_students' => Student::query()->where('status', 'active')->count(),
            'average_completion' => (int) round((int) ($progressSummary->completed ?? 0) / max(1, (int) ($progressSummary->started ?? 0)) * 100),
            'most_watched' => $mostWatched,
            'drop_off' => $dropOff,
        ];
    }
}
