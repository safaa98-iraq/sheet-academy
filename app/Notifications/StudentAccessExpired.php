<?php

namespace App\Notifications;

use App\Models\Student;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class StudentAccessExpired extends Notification
{
    public function __construct(public Student $student, public string $kind, public Carbon $expiredAt) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'summary' => $this->kind === 'subscription' ? 'انتهى اشتراك الطالب؛ تواصل معه للتجديد.' : 'انتهت صلاحية كود الطالب؛ تواصل معه للتجديد.',
            'kind' => $this->kind,
            'student_id' => $this->student->id,
            'student' => $this->student->name,
            'phone' => $this->student->phone,
            'expired_at' => $this->expiredAt->toIso8601String(),
        ];
    }
}
