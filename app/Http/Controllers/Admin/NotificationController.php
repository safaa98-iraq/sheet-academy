<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeInbox($request);
        $notifications = $request->user('web')->notifications()->latest()->paginate(20);

        return view('admin.notifications', compact('notifications'));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorizeInbox($request);
        $unread = $request->user('web')->unreadNotifications();
        $latest = (clone $unread)->latest()->first();

        return response()->json(['count' => $unread->count(), 'latest' => $latest ? [
            'id' => $latest->id, 'message' => ($latest->data['summary'] ?? 'لديك تنبيه جديد.').(isset($latest->data['student']) ? ' — '.$latest->data['student'] : ''),
        ] : null])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        $this->authorizeInbox($request);
        $request->user('web')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back()->with('status', 'تمت قراءة التنبيه.');
    }

    private function authorizeInbox(Request $request): void
    {
        abort_unless($request->user('web')->can('students.manage') || $request->user('web')->can('audit.view'), 403);
    }
}
