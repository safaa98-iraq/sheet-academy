<?php

namespace App\Services;

use App\Models\Student;
use App\Notifications\SubscriptionEndingSoon;
use Illuminate\Support\Facades\DB;

class SubscriptionReminderService
{
    public function send(Student $student, ?string $message = null, bool $deduplicate = true): bool
    {
        return DB::transaction(function () use ($student, $message, $deduplicate): bool {
            $current = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $expiry = $current->subscription_expires_at;
            if ($current->status !== 'active' || $expiry === null || ! $expiry->isFuture()
                || ($deduplicate && $current->subscription_reminder_for?->equalTo($expiry))) {
                return false;
            }
            $current->notify(new SubscriptionEndingSoon($expiry, $message));
            $current->forceFill(['subscription_reminded_at' => now(), 'subscription_reminder_for' => $expiry])->save();

            return true;
        });
    }
}
