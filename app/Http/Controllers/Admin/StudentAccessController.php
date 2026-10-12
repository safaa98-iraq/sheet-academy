<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenewStudentAccessRequest;
use App\Models\Student;
use App\Services\StudentAuditService;
use App\Services\StudentTokenService;
use App\Services\SubscriptionReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudentAccessController extends Controller
{
    public function edit(Request $request, Student $student, StudentTokenService $tokens): View
    {
        $this->authorize('update', $student);
        $token = $student->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->latest('id')->first();

        return view('admin.students.renew', ['student' => $student, 'currentToken' => $token, 'visibleToken' => $token ? $tokens->reveal($token) : null, 'replaceRequested' => $request->query('mode') === 'replace']);
    }

    public function update(RenewStudentAccessRequest $request, Student $student, StudentTokenService $tokens): RedirectResponse
    {
        $data = $request->validated();
        $expiry = $data['subscription_duration'] === 'unlimited' ? null : Carbon::parse($data['subscription_expires_at'], 'Asia/Baghdad')->endOfDay()->utc();
        DB::transaction(function () use ($student, $request, $data, $expiry, $tokens): void {
            $current = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $token = $current->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->latest('id')->first();
            if ($data['mode'] === 'extend' && $token === null) {
                throw ValidationException::withMessages(['mode' => 'لا يوجد رمز صالح للتمديد. اختر إصدار رمز بديل.']);
            }
            $expiryChanged = $current->subscription_expires_at?->toDateTimeString() !== $expiry?->toDateTimeString();
            $current->forceFill(['subscription_expires_at' => $expiry, 'subscription_expiry_notified_at' => null, 'subscription_reminded_at' => $expiryChanged ? null : $current->subscription_reminded_at, 'subscription_reminder_for' => $expiryChanged ? null : $current->subscription_reminder_for,
                'status' => $request->boolean('reactivate') ? 'active' : $current->status])->save();
            if ($data['mode'] === 'replace') {
                $issued = $tokens->issue($current, $request->user('web'), $expiry);
                $issued['record']->update(['status' => $current->status]);
            } else {
                $token->update(['expires_at' => $expiry, 'expiry_notified_at' => null, 'status' => $current->status]);
            }
        });

        app(StudentAuditService::class)->record($student, 'subscription_renewed_by_admin', $request, ['actor_id' => $request->user('web')->id, 'mode' => $data['mode'], 'expires_at' => $expiry?->toIso8601String()]);

        return redirect()->route('admin.students.show', $student)->with('status', $data['mode'] === 'replace' ? 'تم تجديد الاشتراك وإصدار رمز بديل. انسخه من قسم رمز الدخول؛ السابق لم يعد صالحاً.' : 'تم تجديد الاشتراك وصلاحية التوكن مع الاحتفاظ بالرمز نفسه ونقطة المشاهدة.');
    }

    public function remind(Request $request, Student $student, SubscriptionReminderService $reminders): RedirectResponse
    {
        $this->authorize('update', $student);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:600']]);
        if (! $reminders->send($student, $data['message'] ?? null, false)) {
            throw ValidationException::withMessages(['reminder' => 'التذكير متاح للحساب النشط ذي اشتراك محدد ينتهي في المستقبل.']);
        }

        app(StudentAuditService::class)->record($student, 'subscription_reminder_sent_by_admin', $request, ['actor_id' => $request->user('web')->id]);

        return back()->with('status', 'أُرسل تذكير الاشتراك إلى حساب الطالب.');
    }
}
