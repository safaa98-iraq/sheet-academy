<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentTokenLoginRequest;
use App\Models\StudentDevice;
use App\Models\StudentToken;
use App\Services\StudentAuditService;
use App\Services\StudentDeviceBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentTokenAuthController extends Controller
{
    public function store(StudentTokenLoginRequest $request, StudentAuditService $audit, StudentDeviceBindingService $binding): RedirectResponse
    {
        $plainTextToken = trim($request->validated('token'));
        $studentToken = StudentToken::query()
            ->with('student')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($studentToken === null || $studentToken->student->status !== 'active' || ! $studentToken->student->subscriptionIsActive()) {
            $audit->recordFailedToken($plainTextToken, $request);

            return back()->withErrors(['token' => 'رمز الدخول غير صالح أو منتهي.'])->onlyInput('');
        }

        $student = $studentToken->student;
        $credential = $binding->credential($request) ?? bin2hex(random_bytes(32));
        $device = DB::transaction(function () use ($student, $studentToken, $request, $audit, $credential): ?StudentDevice {
            $student = $student->newQuery()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $currentToken = $studentToken->fresh();
            if ($currentToken === null || $currentToken->status !== 'active'
                || $currentToken->expires_at?->isPast() || $student->status !== 'active' || ! $student->subscriptionIsActive()) {
                return null;
            }
            $hash = hash('sha256', $credential);
            if ($student->bound_device_hash !== null && ! hash_equals($student->bound_device_hash, $hash)) {
                $audit->record($student, 'device_login_blocked', $request);

                return null;
            }
            if ($student->bound_device_hash === null) {
                $student->forceFill([
                    'bound_device_hash' => $hash,
                    'bound_device_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'bound_device_at' => now(),
                ])->save();
                $audit->record($student, 'device_bound', $request);
            }
            Auth::guard('student')->login($student);
            $request->session()->regenerate();
            $request->session()->put('student_token_id', $currentToken->id);
            $currentToken->forceFill(['last_used_at' => now()])->save();
            $previousDevices = $student->devices()->whereNull('revoked_at')->get();
            foreach ($previousDevices as $previousDevice) {
                $previousDevice->forceFill(['revoked_at' => now(), 'view_link_hash' => null])->save();
                $audit->record($student, 'session_replaced', $request, ['device_id' => $previousDevice->id], $previousDevice);
            }
            $device = StudentDevice::query()->create([
                'student_id' => $student->id, 'student_token_id' => $currentToken->id,
                'device_name' => mb_substr((string) $request->userAgent(), 0, 255),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'session_id' => $request->session()->getId(), 'last_seen_at' => now(),
            ]);
            $request->session()->put('student_device_id', $device->id);
            $audit->record($student, 'login_succeeded', $request, [], $device);

            return $device;
        }, attempts: 3);
        if ($device === null) {
            return back()->withErrors(['token' => 'الحساب مرتبط بجهاز ومتصفح معتمدين. اطلب من الأستاذ السماح بتغيير الجهاز.'])->onlyInput('');
        }

        return redirect()->route($student->content_agreement_accepted_at ? 'student.learning' : 'student.agreement.show')
            ->withCookie($binding->cookie($credential, $request));
    }

    public function destroy(Request $request, StudentAuditService $audit): RedirectResponse
    {
        $student = $request->user('student');
        $device = StudentDevice::query()->whereKey($request->session()->get('student_device_id'))->where('student_id', $student?->id)->first();
        $audit->record($student, 'logout', $request, [], $device);
        $device?->forceFill(['revoked_at' => now(), 'view_link_hash' => null])->save();
        Auth::guard('student')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('student.login');
    }
}
