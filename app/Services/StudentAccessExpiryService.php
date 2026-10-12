<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentToken;
use App\Models\User;
use App\Notifications\StudentAccessExpired;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class StudentAccessExpiryService
{
    public function notifySubscription(Student $student): bool
    {
        return DB::transaction(function () use ($student): bool {
            $current = Student::whereKey($student->id)->lockForUpdate()->first();
            if ($current === null || $current->status !== 'active' || $current->subscription_expires_at === null
                || $current->subscriptionIsActive() || $current->subscription_expiry_notified_at !== null) {
                return false;
            }
            if (! $this->send($current, 'subscription', $current->subscription_expires_at)) {
                return false;
            }
            $current->forceFill(['subscription_expiry_notified_at' => now()])->save();

            return true;
        });
    }

    public function notifyToken(StudentToken $token): bool
    {
        return DB::transaction(function () use ($token): bool {
            $student = Student::whereKey($token->student_id)->lockForUpdate()->first();
            $current = StudentToken::whereKey($token->id)->lockForUpdate()->first();
            if ($student === null || $student->status !== 'active' || ! $student->subscriptionIsActive()
                || $current === null || $current->status !== 'active' || $current->expires_at === null
                || $current->expires_at->isFuture() || $current->expiry_notified_at !== null) {
                return false;
            }
            if (! $this->send($student, 'token', $current->expires_at)) {
                return false;
            }
            $current->forceFill(['expiry_notified_at' => now()])->save();

            return true;
        });
    }

    private function send(Student $student, string $kind, Carbon $expiredAt): bool
    {
        $admins = User::where('is_active', true)->where(function ($query): void {
            $query->where('is_super_admin', true)
                ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', 'students.manage'));
        })->get();
        if ($admins->isEmpty()) {
            return false;
        }
        Notification::send($admins, new StudentAccessExpired($student, $kind, $expiredAt));

        return true;
    }
}
