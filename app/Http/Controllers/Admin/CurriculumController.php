<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCurriculumNodeRequest;
use App\Http\Requests\Admin\UpdateCurriculumNodeRequest;
use App\Models\Course;
use App\Models\CurriculumNode;
use App\Support\LearningUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CurriculumController extends Controller
{
    private const PARENT_TYPES = ['part' => 'material', 'chapter' => 'part', 'section' => 'chapter', 'lesson' => 'section'];

    public function index(Course $course): View
    {
        $this->authorize('update', $course);
        $nodes = CurriculumNode::query()->where('course_id', $course->id)->with('lesson.attachments')->orderBy('position')->orderBy('id')->get();
        $root = $nodes->first(fn (CurriculumNode $node): bool => $node->type === 'material' && $node->parent_id === null);
        abort_if($root === null, 404);

        return view('admin.curriculum.index', ['course' => $course, 'root' => $root, 'tree' => $this->childrenFor($nodes, $root->id)]);
    }

    public function store(StoreCurriculumNodeRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);
        $data = $request->validated();
        $parent = CurriculumNode::query()->where('course_id', $course->id)->findOrFail($data['parent_id']);
        abort_unless(($parent->type === 'material' && $data['type'] === 'part')
            || ($parent->type === 'part' && $data['type'] === 'chapter')
            || ($parent->type === 'chapter' && $data['type'] === 'section')
            || ($parent->type === 'section' && $data['type'] === 'lesson'), 422, 'لا يمكن إضافة هذا المستوى هنا.');

        DB::transaction(function () use ($course, $parent, $data): void {
            $position = (int) CurriculumNode::query()->where('parent_id', $parent->id)->max('position') + 1;
            $lesson = null;
            if ($data['type'] === 'lesson') {
                $status = $data['publication_status'];
                $lesson = $course->lessons()->create([
                    'title' => $data['title'], 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6)),
                    'type' => $data['content_type'], 'duration_seconds' => $data['duration_seconds'] ?? 0, 'position' => $position,
                    'is_published' => $status === 'published', 'description' => $data['description'] ?? null,
                    'body_html' => $data['body_html'] ?? null, 'publication_status' => $status,
                    'scheduled_at' => $data['scheduled_at'] ?? null, 'video_reference' => $data['video_reference'] ?? null,
                ]);
            }
            $parent->children()->create([
                'course_id' => $course->id, 'lesson_id' => $lesson?->id, 'type' => $data['type'],
                'title' => $data['title'], 'position' => $position, 'publication_status' => $data['publication_status'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
            ]);
        });
        $this->forgetCurriculumCache($course);

        return back()->with('status', 'تمت إضافة المحتوى إلى المنهج.');
    }

    public function update(UpdateCurriculumNodeRequest $request, CurriculumNode $node): RedirectResponse
    {
        $course = $node->course;
        abort_if($course === null, 404);
        $this->authorize('update', $course);
        abort_if($node->type === 'material', 422);
        $data = $request->validated();
        DB::transaction(function () use ($node, $data): void {
            $scheduledAt = $data['publication_status'] === 'scheduled' ? ($data['scheduled_at'] ?? null) : null;
            $node->update(['title' => $data['title'], 'publication_status' => $data['publication_status'], 'scheduled_at' => $scheduledAt]);
            if ($node->type === 'lesson') {
                $node->lesson->update([
                    'title' => $data['title'], 'description' => $data['description'] ?? null, 'body_html' => $data['body_html'] ?? null,
                    'type' => $data['content_type'] ?? $node->lesson->type, 'duration_seconds' => $data['duration_seconds'] ?? 0,
                    'publication_status' => $data['publication_status'], 'scheduled_at' => $scheduledAt,
                    'is_published' => $data['publication_status'] === 'published', 'video_reference' => $data['video_reference'] ?? null,
                ]);
            }
        });
        $this->forgetCurriculumCache($course);

        return back()->with('status', 'تم تحديث المحتوى.');
    }

    public function reorder(Request $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);
        $data = $request->validate([
            'parent_id' => ['required', 'integer', 'exists:curriculum_nodes,id'],
            'node_ids' => ['required', 'array', 'min:1'],
            'node_ids.*' => ['required', 'integer', 'distinct', 'exists:curriculum_nodes,id'],
        ]);
        $parent = CurriculumNode::query()->where('course_id', $course->id)->findOrFail($data['parent_id']);
        $nodes = CurriculumNode::query()->where('course_id', $course->id)->whereIn('id', $data['node_ids'])->get()->keyBy('id');
        abort_unless($nodes->count() === count($data['node_ids']), 422, 'المحتوى المحدد لا يتبع هذه المادة.');
        $expectedType = array_search($parent->type, self::PARENT_TYPES, true);
        abort_unless($expectedType !== false && $nodes->every(fn (CurriculumNode $node): bool => $node->type === $expectedType), 422, 'لا يمكن نقل هذا المستوى إلى الوجهة المحددة.');
        abort_if($nodes->contains('id', $parent->id), 422);

        DB::transaction(function () use ($data, $nodes, $parent): void {
            foreach ($data['node_ids'] as $position => $id) {
                $node = $nodes->get((int) $id);
                $this->assertNotDescendant($node, $parent);
                $node->update(['parent_id' => $parent->id, 'position' => $position]);
                if ($node->type === 'lesson') {
                    $node->lesson()->update(['position' => $position]);
                }
            }
        });
        $this->forgetCurriculumCache($course);

        return response()->json(['message' => 'تم حفظ ترتيب المنهج.']);
    }

    public function destroy(CurriculumNode $node): RedirectResponse
    {
        $course = $node->course;
        abort_if($course === null, 404);
        $this->authorize('delete', $course);
        abort_if($node->type === 'material', 422);
        $this->softDeleteBranch($node);
        $this->forgetCurriculumCache($course);

        return back()->with('status', 'تم حذف المحتوى حذفاً ناعماً.');
    }

    public function preview(Request $request, Course $course): View
    {
        $this->authorize('view', $course);
        $course->loadMissing('lessons.progress', 'lessons.attachments');
        $courseData = LearningUi::course($course, null, true);

        if ($request->filled('lesson')) {
            $lessonData = LearningUi::lesson($courseData, (string) $request->query('lesson'), true);

            return view('student.lesson', [
                'lessonData' => $lessonData, 'curriculum' => $courseData['lessons'], 'selectedCourse' => $courseData,
                'courseCards' => [$courseData], 'preview' => true, 'page' => 'lesson', 'previewCourseId' => $course->id,
            ]);
        }

        return view('student.course', ['selectedCourse' => $courseData, 'courseCards' => [$courseData], 'preview' => true, 'page' => 'course', 'previewCourseId' => $course->id]);
    }

    /** @return Collection<int, CurriculumNode> */
    private function childrenFor(Collection $nodes, int $parentId): Collection
    {
        return $nodes->where('parent_id', $parentId)->values()->map(function (CurriculumNode $node) use ($nodes): CurriculumNode {
            $node->setRelation('children', $this->childrenFor($nodes, $node->id));

            return $node;
        });
    }

    private function assertNotDescendant(CurriculumNode $node, CurriculumNode $parent): void
    {
        $current = $parent;
        while ($current !== null) {
            abort_if($current->id === $node->id, 422, 'لا يمكن نقل المحتوى داخل نفسه أو أحد فروعه.');
            $current = $current->parent;
        }
    }

    private function softDeleteBranch(CurriculumNode $node): void
    {
        foreach ($node->children as $child) {
            $this->softDeleteBranch($child);
        }
        if ($node->type === 'lesson' && $node->lesson !== null) {
            $node->lesson->attachments()->delete();
            $node->lesson->delete();
        }
        $node->delete();
    }

    private function forgetCurriculumCache(Course $course): void
    {
        Cache::forget('curriculum:course:'.$course->id.':published');
        Cache::forget('curriculum:course:'.$course->id.':preview');
    }
}
