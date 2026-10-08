<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\StudentToken;
use App\Models\User;
use App\Notifications\SuspiciousStudentActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class StudentAuditService
{
    /** @var array<string, int> */
    private const POINTS = [
        'login_failed' => 1, 'login_succeeded' => 0, 'logout' => 0, 'view_exited' => 0, 'watch_started' => 0, 'watch_heartbeat' => 0,
        'document_opened' => 0, 'lesson_opened' => 0, 'suspicious_devtools' => 3, 'suspicious_copy' => 2,
        'suspicious_print' => 2, 'suspicious_watermark' => 5, 'multiple_device_login' => 5,
        'stale_view_link' => 1, 'automatic_suspension' => 0,
        'suspicious_activity_flood' => 5,
    ];

    /** @param array<string, mixed> $metadata */
    public function record(?Student $student, string $event, Request $request, array $metadata = [], ?StudentDevice $device = null): AuditLog
    {
        $points = self::POINTS[$event] ?? 0;
        $priorPoints = AuditLog::query()->where('created_at', '>=', now()->subHour())
            ->when($student !== null, fn ($query) => $query->where('student_id', $student->id), fn ($query) => $query->whereNull('student_id')->where('ip_address', $request->ip()))
            ->sum('points');
        $log = AuditLog::query()->create([
            'student_id' => $student?->id,
            'student_device_id' => $device?->id ?? $request->session()->get('student_device_id'),
            'event' => $event,
            'points' => $points,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'metadata' => $metadata,
        ]);

        if ($student !== null && $points > 0) {
            $student->increment('violation_score', $points);
            $student->refresh();
            $threshold = (int) config('audit.auto_suspend_score', 0);
            if ($threshold > 0 && $student->violation_score >= $threshold && $student->status === 'active') {
                $student->forceFill(['status' => 'suspended'])->save();
                $student->tokens()->where('status', 'active')->update(['status' => 'revoked']);
                $student->devices()->whereNull('revoked_at')->update(['revoked_at' => now(), 'view_link_hash' => null]);
                AuditLog::query()->create([
                    'student_id' => $student->id, 'student_device_id' => $device?->id,
                    'event' => 'automatic_suspension', 'points' => 0, 'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                    'metadata' => ['threshold' => $threshold, 'score' => $student->violation_score],
                ]);
            }
        }

        $this->notifyAdminsWhenNeeded($student, $event, $request, $points, $priorPoints);

        return $log;
    }

    public function recordFailedToken(string $plainTextToken, Request $request): void
    {
        $student = StudentToken::query()->with('student')->where('token_hash', hash('sha256', $plainTextToken))->first()?->student;
        $this->record($student, 'login_failed', $request);
    }

    public function watermarkText(Student $student): string
    {
        $identifier = strtoupper(substr(hash_hmac('sha256', 'watermark:'.$student->id, (string) config('app.key')), 0, 16));

        return $identifier.' · رقم الطالب '.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT).' · '.$student->name;
    }

    private function notifyAdminsWhenNeeded(?Student $student, string $event, Request $request, int $points, int $priorPoints): void
    {
        $threshold = (int) config('audit.alert_threshold', 3);
        if ($points < $threshold && ($priorPoints >= $threshold || $priorPoints + $points < $threshold)) {
            return;
        }
        $admins = User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => $user->hasPermissionTo('audit.view'));
        if ($admins->isEmpty()) {
            return;
        }
        Notification::send($admins, new SuspiciousStudentActivity([
            'summary' => $this->eventLabel($event), 'event' => $event,
            'student' => $student?->name, 'student_id' => $student?->id,
            'ip_address' => $request->ip(), 'points' => $points, 'occurred_at' => now()->toIso8601String(),
        ]));
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'login_failed' => 'محاولة دخول برمز غير صالح.',
            'suspicious_devtools' => 'تم رصد محاولة فتح أدوات المطور أثناء عرض المحتوى.',
            'suspicious_copy' => 'تم رصد محاولة نسخ أو قص محتوى محمي.',
            'suspicious_print' => 'تم رصد محاولة طباعة محتوى محمي.',
            'suspicious_watermark' => 'عبثت الصفحة بطبقة البصمة المرئية.',
            'multiple_device_login' => 'أنهى تسجيل دخول جديد جلسة جهاز آخر.',
            'stale_view_link' => 'محاولة فتح رابط مشاهدة قديم أو منتهي.',
            'suspicious_activity_flood' => 'تجاوز حد طلبات النشاط؛ أُبطل رابط العرض لحماية المحتوى.',
            default => 'سُجل نشاط يحتاج مراجعة.',
        };
    }
}
