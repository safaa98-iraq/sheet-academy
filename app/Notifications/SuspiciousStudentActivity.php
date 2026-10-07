<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class SuspiciousStudentActivity extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    /** @param array<string, mixed> $activity */
    public function __construct(public array $activity) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('audit.email_enabled') ? ['database', 'mail'] : ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تنبيه نشاط طالب يحتاج مراجعة')
            ->greeting('تنبيه من منصة عيادة التعلّم')
            ->line((string) ($this->activity['summary'] ?? 'سُجّل نشاط غير معتاد.'))
            ->action('فتح سجل النشاط', URL::route('admin.activity'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->activity;
    }
}
