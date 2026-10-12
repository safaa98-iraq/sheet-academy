<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\SyncCourseStudentsRequest;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\BrandImageService;
use App\Services\ContentDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CourseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Course::class);

        return view('admin.courses.index', ['courses' => Course::with('gradeLevel')->withCount('lessons')->selectSub(Student::query()->selectRaw('COUNT(*)')->where(function ($query): void {
            $query->where(fn ($grade) => $grade->whereNotNull('students.grade_level_id')->whereColumn('students.grade_level_id', 'courses.grade_level_id'))
                ->orWhere(fn ($custom) => $custom->whereNull('students.grade_level_id')->whereExists(fn ($enrollment) => $enrollment->selectRaw('1')->from('course_student')->whereColumn('course_student.student_id', 'students.id')->whereColumn('course_student.course_id', 'courses.id')));
        }), 'students_count')->when($request->filled('grade_level_id'), fn ($query) => $query->where('grade_level_id', $request->integer('grade_level_id')))->orderBy('position')->orderByDesc('id')->get(), 'gradeLevels' => GradeLevel::orderBy('position')->orderBy('id')->get()]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Course::class);

        if (! GradeLevel::exists()) {
            return redirect()->route('admin.grade-levels.create')->with('status', 'أضف مرحلة صفية أولاً، ثم أنشئ المادة ودروسها.');
        }

        return view('admin.courses.form', ['course' => new Course(['grade_level_id' => $request->integer('grade_level_id') ?: null]), 'gradeLevels' => GradeLevel::orderBy('position')->orderBy('id')->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $this->authorize('create', Course::class);
        $data = $request->validated();
        $course = DB::transaction(function () use ($data, $request): Course {
            $status = $data['publication_status'] ?? (($data['is_published'] ?? false) ? 'published' : 'draft');
            $course = Course::create([
                'grade_level_id' => $data['grade_level_id'], 'title' => $data['title'], 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6)),
                'description' => $data['description'] ?? null, 'published_at' => $status === 'published' ? now() : null,
                'publication_status' => $status, 'scheduled_at' => $status === 'scheduled' ? $data['scheduled_at'] : null,
                'cover_image' => $request->hasFile('cover') ? '/'.app(BrandImageService::class)->store($request->file('cover'), 'cover') : null,
                'created_by' => $request->user('web')->id, 'position' => (int) Course::max('position') + 1,
            ]);
            $course->curriculumRoot()->create([
                'course_id' => $course->id, 'type' => 'material', 'title' => $course->title,
                'position' => $course->position, 'publication_status' => $course->publication_status,
                'scheduled_at' => $course->scheduled_at,
            ]);

            return $course;
        });

        return redirect()->route('admin.courses.edit', $course)->with('status', 'تم إنشاء المادة. أضف الطلاب والدروس.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Course $course): RedirectResponse
    {
        return redirect()->route('admin.courses.edit', $course);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Course $course): View
    {
        $this->authorize('update', $course);

        return view('admin.courses.form', ['course' => $course->load(['students', 'lessons']), 'students' => Student::whereNull('grade_level_id')->orderBy('name')->get(), 'gradeLevels' => GradeLevel::orderBy('position')->orderBy('id')->get()]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreCourseRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);
        $data = $request->validated();
        $status = $data['publication_status'] ?? (($data['is_published'] ?? false) ? 'published' : 'draft');
        $course->update([
            'cover_image' => $request->hasFile('cover') ? '/'.app(BrandImageService::class)->store($request->file('cover'), 'cover') : ($request->boolean('remove_cover') ? null : $course->cover_image),
            'grade_level_id' => $data['grade_level_id'], 'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'published_at' => $status === 'published' ? ($course->published_at ?? now()) : null,
            'publication_status' => $status,
            'scheduled_at' => $status === 'scheduled' ? ($data['scheduled_at'] ?? null) : null,
        ]);
        $root = $course->curriculumRoot()->firstOrCreate([], [
            'course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'position' => $course->position,
        ]);
        $root->update([
            'title' => $course->title,
            'publication_status' => $course->publication_status,
            'scheduled_at' => $course->scheduled_at,
        ]);
        Cache::forget('curriculum:course:'.$course->id.':published');
        Cache::forget('curriculum:course:'.$course->id.':preview');

        return back()->with('status', 'تم حفظ المادة.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Course $course, ContentDeletionService $deletion): RedirectResponse
    {
        $this->authorize('delete', $course);
        $deletion->delete($request, $course);

        return redirect()->route('admin.courses.index')->with('status', 'أُرشفت المادة ودروسها وحُجبت عن الطلاب، مع حفظ حساباتهم وتقدّمهم.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('updateAny', Course::class);
        $data = $request->validate([
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['required', 'integer', 'distinct', 'exists:courses,id'],
        ]);
        $courses = Course::query()->whereIn('id', $data['course_ids'])->get()->keyBy('id');
        abort_unless($courses->count() === count($data['course_ids']), 422);

        DB::transaction(function () use ($data, $courses): void {
            foreach ($data['course_ids'] as $position => $id) {
                $course = $courses->get((int) $id);
                $course->update(['position' => $position]);
                $course->curriculumRoot()->update(['position' => $position]);
            }
        });

        return response()->json(['message' => 'تم حفظ ترتيب المواد.']);
    }

    public function syncStudents(SyncCourseStudentsRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);
        $course->students()->sync($request->validated('student_ids', []));

        return back()->with('status', 'تم تحديث تسجيلات الطلاب في المادة.');
    }
}
