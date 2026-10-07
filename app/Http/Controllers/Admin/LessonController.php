<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLessonRequest;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LessonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.courses.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Course $course): View
    {
        return view('admin.lessons.form', ['course' => $course, 'lesson' => new Lesson]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLessonRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);
        $data = $request->validated();
        $publicationStatus = $data['publication_status'] ?? (($data['is_published'] ?? false) ? 'published' : 'draft');
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
        $this->forgetCurriculumCache($course);

        return redirect()->route('admin.courses.edit', $course)->with('status', 'تم إضافة الدرس.');
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

        return view('admin.lessons.form', ['course' => $lesson->course, 'lesson' => $lesson]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreLessonRequest $request, Lesson $lesson): RedirectResponse
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
        $lesson->update($data);
        $lesson->curriculumNode()->update([
            'title' => $lesson->title,
            'publication_status' => $lesson->publication_status,
            'scheduled_at' => $lesson->scheduled_at,
        ]);
        $this->forgetCurriculumCache($lesson->course);

        return redirect()->route('admin.courses.edit', $lesson->course)->with('status', 'تم تحديث الدرس.');
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
            'publication_status' => $course->published_at ? 'published' : 'draft',
        ]);
        $part = $root->children()->firstOrCreate(['type' => 'part'], ['course_id' => $course->id, 'title' => 'الجزء الأول', 'publication_status' => 'published']);
        $chapter = $part->children()->firstOrCreate(['type' => 'chapter'], ['course_id' => $course->id, 'title' => 'الفصل الأول', 'publication_status' => 'published']);
        $section = $chapter->children()->firstOrCreate(['type' => 'section'], ['course_id' => $course->id, 'title' => 'القسم الأول', 'publication_status' => 'published']);
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
