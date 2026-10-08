<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLessonRequest;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LessonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Course::class);
        $gradeLevels = GradeLevel::orderBy('position')->orderBy('id')->get();
        $courses = Course::with('gradeLevel')->orderBy('position')->orderBy('id')->get(['id', 'title', 'grade_level_id']);
        $selectedCourse = $courses->firstWhere('id', $request->integer('course_id'));
        if ($request->filled('grade_level_id') && $selectedCourse?->grade_level_id !== $request->integer('grade_level_id')) {
            $selectedCourse = null;
        }
        $selectedCourse?->load(['lessons.video', 'lessons.attachments']);

        return view('admin.lessons.index', compact('gradeLevels', 'courses', 'selectedCourse'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Course $course): View
    {
        $this->authorize('update', $course);

        return view('admin.lessons.form', ['course' => $course->load('gradeLevel'), 'lesson' => new Lesson(['type' => 'text', 'publication_status' => 'draft'])]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLessonRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);
        $data = $request->validated();
        $publicationStatus = $data['publication_status'] ?? (($data['is_published'] ?? false) ? 'published' : 'draft');
        $lesson = DB::transaction(function () use ($course, $data, $publicationStatus): Lesson {
            $lesson = $course->lessons()->create([
                ...$data,
                'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(5)),
                'duration_seconds' => $data['duration_seconds'] ?? 0,
                'position' => $data['position'] ?? ($course->lessons()->max('position') + 1),
                'publication_status' => $publicationStatus,
                'scheduled_at' => $publicationStatus === 'scheduled' ? ($data['scheduled_at'] ?? null) : null,
                'is_published' => $publicationStatus === 'published',
            ]);
            $this->attachToDefaultSection($course, $lesson);

            return $lesson;
        });
        $this->forgetCurriculumCache($course);

        return redirect()->route('admin.lessons.edit', $lesson)->with('status', 'تم إنشاء المحاضرة. أضف الفيديو والمرفقات من هذه الصفحة.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Lesson $lesson): RedirectResponse
    {
        return redirect()->route('admin.lessons.edit', $lesson);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Lesson $lesson): View
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);

        return view('admin.lessons.form', ['course' => $lesson->course->load('gradeLevel'), 'lesson' => $lesson->load('video', 'attachments')]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreLessonRequest $request, Lesson $lesson): RedirectResponse|JsonResponse
    {
        abort_if($lesson->course === null, 404);
        $this->authorize('update', $lesson->course);
        $data = $request->validated();
        if (isset($data['title'])) {
            $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(5));
        }
        if (isset($data['is_published']) && ! isset($data['publication_status'])) {
            $data['publication_status'] = $data['is_published'] ? 'published' : 'draft';
        }
        if (($data['publication_status'] ?? null) !== 'scheduled') {
            $data['scheduled_at'] = null;
        }
        if (isset($data['publication_status'])) {
            $data['is_published'] = $data['publication_status'] === 'published';
        }
        if ($lesson->video?->status === 'ready') {
            $data['duration_seconds'] = $lesson->video->duration_seconds ?? $lesson->duration_seconds;
        }
        $lesson->update($data);
        $lesson->curriculumNode()->update([
            'title' => $lesson->title,
            'publication_status' => $lesson->publication_status,
            'scheduled_at' => $lesson->scheduled_at,
        ]);
        $this->forgetCurriculumCache($lesson->course);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تم حفظ المحاضرة.']);
        }

        return redirect()->route('admin.lessons.edit', $lesson)->with('status', 'تم حفظ المحاضرة.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lesson $lesson): RedirectResponse
    {
        $course = $lesson->course;
        abort_if($course === null, 404);
        $this->authorize('update', $course);
        $lesson->attachments()->delete();
        $lesson->curriculumNode()->delete();
        $lesson->delete();
        $this->forgetCurriculumCache($course);

        return redirect()->route('admin.courses.edit', $course)->with('status', 'تم حذف الدرس.');
    }

    private function attachToDefaultSection(Course $course, Lesson $lesson): void
    {
        $root = $course->curriculumRoot()->firstOrCreate([], [
            'course_id' => $course->id, 'type' => 'material', 'title' => $course->title,
            'publication_status' => $course->publication_status === 'draft' && $course->published_at !== null ? 'published' : $course->publication_status,
            'scheduled_at' => $course->scheduled_at,
        ]);
        $part = $root->children()->firstOrCreate(['type' => 'part', 'publication_status' => 'published'], ['course_id' => $course->id, 'title' => 'الجزء الأول', 'publication_status' => 'published']);
        $chapter = $part->children()->firstOrCreate(['type' => 'chapter', 'publication_status' => 'published'], ['course_id' => $course->id, 'title' => 'الفصل الأول', 'publication_status' => 'published']);
        $section = $chapter->children()->firstOrCreate(['type' => 'section', 'publication_status' => 'published'], ['course_id' => $course->id, 'title' => 'القسم الأول', 'publication_status' => 'published']);
        $section->children()->create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title,
            'position' => $lesson->position, 'publication_status' => $lesson->publication_status,
            'scheduled_at' => $lesson->scheduled_at,
        ]);
    }

    private function forgetCurriculumCache(Course $course): void
    {
        Cache::forget('curriculum:course:'.$course->id.':published');
        Cache::forget('curriculum:course:'.$course->id.':preview');
    }
}
