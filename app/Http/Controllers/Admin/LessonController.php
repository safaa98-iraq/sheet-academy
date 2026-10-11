<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLessonRequest;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Services\LessonAttachmentService;
use App\Services\LessonPublicationService;
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

        $lectures = Lesson::with(['course.gradeLevel', 'video', 'attachments'])
            ->when($selectedCourse !== null, fn ($query) => $query->where('course_id', $selectedCourse->id))
            ->when($request->filled('grade_level_id'), fn ($query) => $query->whereHas('course', fn ($query) => $query->where('grade_level_id', $request->integer('grade_level_id'))))
            ->when($request->filled('course_id') && $selectedCourse === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->whereHas('course')->latest('id')->paginate(20)->withQueryString();

        return view('admin.lessons.index', compact('gradeLevels', 'courses', 'selectedCourse', 'lectures'));
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
    public function store(StoreLessonRequest $request, Course $course): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $course);
        $data = $request->safe()->except(['attachments', 'publish_course', 'enabled_resolutions', 'creation_key']);
        $creationKey = $request->filled('creation_key') ? hash('sha256', $request->user('web')->id.'|'.$course->id.'|'.$request->validated('creation_key')) : null;
        $publicationStatus = $data['publication_status'] ?? (($data['is_published'] ?? false) ? 'published' : 'draft');
        $lesson = DB::transaction(function () use ($course, $data, $publicationStatus, $request, $creationKey): Lesson {
            Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            if ($creationKey !== null) {
                $existing = $course->lessons()->withTrashed()->where('creation_key', $creationKey)->first();
                if ($existing !== null) {
                    abort_if($existing->trashed(), 409, 'المحاضرة السابقة محذوفة. افتح نموذج محاضرة جديدة.');

                    return $existing;
                }
            }
            $lesson = $course->lessons()->create([
                ...$data,
                'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(5)),
                'duration_seconds' => $data['duration_seconds'] ?? 0,
                'position' => $data['position'] ?? ($course->lessons()->max('position') + 1),
                'publication_status' => $publicationStatus,
                'scheduled_at' => $publicationStatus === 'scheduled' ? ($data['scheduled_at'] ?? null) : null,
                'is_published' => $publicationStatus === 'published',
            ]);
            $lesson->forceFill(['creation_key' => $creationKey])->save();
            $this->attachToDefaultSection($course, $lesson);
            app(LessonAttachmentService::class)->store($lesson, $request->validated('attachments', []));

            return $lesson;
        });
        $this->publishCourseIfRequested($request, $course, $lesson);
        $this->forgetCurriculumCache($course);
        app(LessonPublicationService::class)->notify($lesson);
        if ($request->expectsJson()) {
            return response()->json($this->editorUrls($lesson), 201);
        }

        return redirect()->route('admin.lessons.edit', $lesson)->with('status', 'تم حفظ المحاضرة والمرفقات.');
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
        $data = $request->safe()->except(['attachments', 'publish_course', 'enabled_resolutions', 'creation_key']);
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
        $data['duration_seconds'] = $data['duration_seconds'] ?? $lesson->duration_seconds;
        $data['position'] = $data['position'] ?? $lesson->position;
        if ($lesson->video?->status === 'ready') {
            $data['duration_seconds'] = $lesson->video->duration_seconds ?? $lesson->duration_seconds;
        }
        DB::transaction(function () use ($lesson, $data, $request): void {
            if ($request->has('enabled_resolutions')) {
                abort_unless($lesson->video?->status === 'ready', 409);
                $lesson->video->update(['enabled_resolutions' => array_map('intval', $request->validated('enabled_resolutions'))]);
            }
            $lesson->update($data);
            app(LessonAttachmentService::class)->store($lesson, $request->validated('attachments', []));
        });
        $lesson->curriculumNode()->update([
            'title' => $lesson->title,
            'publication_status' => $lesson->publication_status,
            'scheduled_at' => $lesson->scheduled_at,
        ]);
        $this->publishCourseIfRequested($request, $lesson->course, $lesson);
        $this->forgetCurriculumCache($lesson->course);
        app(LessonPublicationService::class)->notify($lesson);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تم حفظ المحاضرة.', ...$this->editorUrls($lesson)]);
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

    private function publishCourseIfRequested(StoreLessonRequest $request, Course $course, Lesson $lesson): void
    {
        if ($request->boolean('publish_course') && in_array($lesson->publication_status, ['published', 'scheduled'], true)) {
            $scheduledAt = $lesson->publication_status === 'scheduled' ? $lesson->scheduled_at : null;
            $course->update(['publication_status' => $lesson->publication_status, 'published_at' => $scheduledAt === null ? now() : null, 'scheduled_at' => $scheduledAt]);
            $course->curriculumRoot()->update(['publication_status' => $lesson->publication_status, 'scheduled_at' => $scheduledAt]);
        }
    }

    private function editorUrls(Lesson $lesson): array
    {
        return ['lesson_id' => $lesson->id, 'edit_url' => route('admin.lessons.edit', $lesson), 'update_url' => route('admin.lessons.update', $lesson),
            'attachments_url' => route('admin.lessons.attachments.store', $lesson),
            'upload_url' => route('admin.lessons.video-uploads.start', $lesson), 'status_url' => route('admin.lessons.video-status', $lesson)];
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
