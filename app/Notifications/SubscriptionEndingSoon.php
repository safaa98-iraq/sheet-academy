<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class SubscriptionEndingSoon extends Notification
{
    public function __construct(public Carbon $expiresAt, public ?string $message = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'subscription_reminder', 'title' => 'اقترب موعد انتهاء اشتراكك', 'message' => $this->message ?: 'ينتهي اشتراكك بتاريخ '.$this->expiresAt->copy()->timezone('Asia/Baghdad')->format('Y-m-d').'. تواصل مع الأستاذ إذا رغبت بالتجديد.', 'expires_at' => $this->expiresAt->toIso8601String()];
    }
}
