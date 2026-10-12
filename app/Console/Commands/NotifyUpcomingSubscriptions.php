<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\SiteBrandService;
use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class NotifyUpcomingSubscriptions extends Command
{
    protected $signature = 'app:notify-upcoming-subscriptions';

    protected $description = 'Send opted-in, deduplicated subscription reminders to students.';

    public function handle(SiteBrandService $brand, SubscriptionReminderService $reminders): int
    {
        $settings = $brand->current();
        if (! $settings['subscription_reminder_enabled']) {
            return self::SUCCESS;
        }
        Student::where('status', 'active')->where('subscription_expires_at', '>', now())
            ->where('subscription_expires_at', '<=', now()->addDays((int) $settings['subscription_reminder_days']))
            ->chunkById(100, function ($students) use ($reminders): void {
                foreach ($students as $student) {
                    $reminders->send($student);
                }
            });

        return self::SUCCESS;
    }
}
