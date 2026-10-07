<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\StudentPlaylist;
use App\Models\StudentPlaylistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StudentPlaylistController extends Controller
{
    public function index(Request $request): View
    {
        $student = $request->user('student');
        $playlist = isset($request->query()['playlist_id'])
            ? $student->playlists()->findOrFail($request->integer('playlist_id'))
            : $student->playlists()->firstOrCreate(['name' => 'قائمتي']);
        $playlist->load(['items.lesson.course', 'items.lesson.progress' => fn ($query) => $query->where('student_id', $student->id)]);
        $courses = $student->accessibleCourses()->visibleForStudents()->with(['lessons' => fn ($query) => $query->visibleForStudents()->orderBy('title')])->orderBy('title')->get();

        return view('student.playlist', ['playlist' => $playlist, 'playlists' => $student->playlists()->orderBy('id')->get(), 'courses' => $courses]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']], ['name.required' => 'اسم القائمة مطلوب.']);
        $request->user('student')->playlists()->create($data);

        return redirect()->route('student.playlist')->with('status', 'أُنشئت قائمة التشغيل.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'playlist_id' => ['nullable', 'integer', 'exists:student_playlists,id'],
        ], ['lesson_id.required' => 'اختر درساً لإضافته.']);
        $student = $request->user('student');
        $lesson = Lesson::query()->findOrFail($data['lesson_id']);
        abort_unless(Gate::forUser($student)->allows('viewByStudent', $lesson), 404);
        $playlist = isset($data['playlist_id'])
            ? $student->playlists()->findOrFail($data['playlist_id'])
            : $student->playlists()->firstOrCreate(['name' => 'قائمتي']);
        $playlist->items()->firstOrCreate(['lesson_id' => $lesson->id], ['position' => (int) $playlist->items()->max('position') + 1]);

        return back()->with('status', 'أُضيف الدرس إلى قائمة التشغيل.');
    }

    public function destroy(Request $request, StudentPlaylistItem $item): RedirectResponse
    {
        abort_unless($item->playlist()->where('student_id', $request->user('student')->id)->exists(), 404);
        $item->delete();

        return back()->with('status', 'حُذف الدرس من القائمة.');
    }

    public function reorder(Request $request, StudentPlaylist $playlist): RedirectResponse
    {
        abort_unless($playlist->student_id === $request->user('student')->id, 404);
        $data = $request->validate(['item_ids' => ['required', 'array', 'max:500'], 'item_ids.*' => ['required', 'integer', 'distinct']]);
        $items = $playlist->items()->whereIn('id', $data['item_ids'])->get()->keyBy('id');
        abort_unless($items->count() === count($data['item_ids']), 422);
        DB::transaction(function () use ($data, $items): void {
            foreach ($data['item_ids'] as $position => $itemId) {
                $items[$itemId]->update(['position' => $position]);
            }
        });

        return back()->with('status', 'حُفظ ترتيب قائمة التشغيل.');
    }
}
