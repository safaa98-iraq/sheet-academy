<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user('student')->notifications()->latest()->paginate(20);
        $lessons = Lesson::whereIn('id', $notifications->map(fn ($notification) => $notification->data['lesson_id'] ?? null))->with('course')->get()->keyBy('id');
        $links = [];
        foreach ($lessons as $lesson) {
            if (Gate::forUser($request->user('student'))->allows('viewByStudent', $lesson)) {
                $links[$lesson->id] = route('student.lesson.show', $lesson);
            }
        }

        return view('student.notifications', compact('notifications', 'links'));
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        $request->user('student')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back()->with('status', 'تمت قراءة التنبيه.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $unread = $request->user('student')->unreadNotifications();
        $latest = (clone $unread)->latest()->first();

        return response()->json(['count' => $unread->count(), 'latest' => $latest ? [
            'id' => $latest->id,
            'message' => ($latest->data['kind'] ?? null) === 'subscription_reminder' ? $latest->data['message'] : 'محاضرة جديدة: '.($latest->data['title'] ?? 'محاضرة جديدة').' — '.($latest->data['course_title'] ?? 'موادك'),
        ] : null])->header('Cache-Control', 'private, no-store');
    }
}
