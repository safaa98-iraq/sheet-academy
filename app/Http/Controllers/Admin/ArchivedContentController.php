<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Services\ContentRestorationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArchivedContentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('courses.manage');
        $filters = $request->validate(['type' => ['sometimes', 'in:grade,course,lesson'], 'q' => ['nullable', 'string', 'max:150']]);
        $type = $filters['type'] ?? 'grade';
        $query = match ($type) {
            'grade' => GradeLevel::onlyTrashed()->withCount(['courses' => fn ($q) => $q->withTrashed()]),
            'course' => Course::onlyTrashed()->with(['gradeLevel' => fn ($q) => $q->withTrashed()])->withCount(['lessons' => fn ($q) => $q->withTrashed()]),
            'lesson' => Lesson::onlyTrashed()->with(['course' => fn ($q) => $q->withTrashed(), 'course.gradeLevel' => fn ($q) => $q->withTrashed()]),
        };
        if (! empty($filters['q'])) {
            $query->where($type === 'grade' ? 'name' : 'title', 'like', '%'.$filters['q'].'%');
        }
        $items = $query->orderByDesc('deleted_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.archive', ['items' => $items, 'type' => $type, 'filters' => $filters, 'counts' => ['grade' => GradeLevel::onlyTrashed()->count(), 'course' => Course::onlyTrashed()->count(), 'lesson' => Lesson::onlyTrashed()->count()]]);
    }

    public function restore(Request $request, string $type, int $id, ContentRestorationService $restoration): RedirectResponse
    {
        $this->authorize('courses.manage');
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $restoration->restore($type, $id, $request);

        return redirect()->route('admin.archive.index', ['type' => $type])->with('status', 'تم استرجاع العنصر. راجع حالة نشره؛ المواد والمحاضرات الأخرى المؤرشفة تبقى في الأرشيف حتى تسترجعها.');
    }
}
