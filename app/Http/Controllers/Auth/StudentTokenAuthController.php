<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentTokenLoginRequest;
use App\Models\StudentDevice;
use App\Models\StudentToken;
use App\Services\StudentAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentTokenAuthController extends Controller
{
    public function store(StudentTokenLoginRequest $request, StudentAuditService $audit): RedirectResponse
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

        if ($studentToken === null || $studentToken->student->status !== 'active') {
            $audit->recordFailedToken($plainTextToken, $request);

            return back()->withErrors(['token' => 'رمز الدخول غير صالح أو منتهي.'])->onlyInput('');
        }

        $student = $studentToken->student;
        Auth::guard('student')->login($student);
        $request->session()->regenerate();
        $request->session()->put('student_token_id', $studentToken->id);
        $studentToken->forceFill(['last_used_at' => now()])->save();
        $device = StudentDevice::query()->create([
            'student_id' => $student->id, 'student_token_id' => $studentToken->id,
            'device_name' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'session_id' => $request->session()->getId(), 'last_seen_at' => now(),
        ]);
        $request->session()->put('student_device_id', $device->id);
        $deviceLimit = max(1, min(10, (int) $studentToken->device_limit));
        $activeDevices = StudentDevice::query()->where('student_id', $student->id)->whereNull('revoked_at')->latest('id')->get();
        foreach ($activeDevices->skip($deviceLimit) as $previousDevice) {
            $previousDevice->forceFill(['revoked_at' => now(), 'view_link_hash' => null])->save();
            $audit->record($student, 'multiple_device_login', $request, ['device_id' => $previousDevice->id], $previousDevice);
        }
        $audit->record($student, 'login_succeeded', $request, [], $device);

        return redirect()->route($student->content_agreement_accepted_at ? 'student.learning' : 'student.agreement.show');
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
