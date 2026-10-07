<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:60'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية.']);
        $request->user('web')->unreadNotifications()->update(['read_at' => now()]);
        $logs = AuditLog::query()->with(['student:id,name,email', 'device:id,device_name'])
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where('event', $event))
            ->when($filters['student_id'] ?? null, fn ($query, int $studentId) => $query->where('student_id', $studentId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest()->paginate(30)->withQueryString();
        $students = Student::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.audit.index', ['logs' => $logs, 'students' => $students, 'filters' => $filters]);
    }
}
