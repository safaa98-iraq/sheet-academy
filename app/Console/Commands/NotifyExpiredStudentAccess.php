<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentToken;
use App\Services\StudentAccessExpiryService;
use Illuminate\Console\Command;

class NotifyExpiredStudentAccess extends Command
{
    protected $signature = 'app:notify-expired-student-access';

    protected $description = 'Notify authorized admins once when student subscriptions or active access codes expire.';

    public function handle(StudentAccessExpiryService $expiry): int
    {
        $count = 0;
        Student::where('status', 'active')->where('subscription_expires_at', '<=', now())->whereNull('subscription_expiry_notified_at')
            ->chunkById(100, function ($students) use ($expiry, &$count): void {
                foreach ($students as $student) {
                    $count += (int) $expiry->notifySubscription($student);
                }
            });
        StudentToken::where('status', 'active')->where('expires_at', '<=', now())->whereNull('expiry_notified_at')
            ->whereHas('student', fn ($query) => $query->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('subscription_expires_at')->orWhere('subscription_expires_at', '>', now())))
            ->chunkById(100, function ($tokens) use ($expiry, &$count): void {
                foreach ($tokens as $token) {
                    $count += (int) $expiry->notifyToken($token);
                }
            });
        $this->info("Sent {$count} expiry alerts.");

        return self::SUCCESS;
    }
}
