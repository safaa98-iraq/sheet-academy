<?php

namespace App\Http\Middleware;

use App\Models\StudentDevice;
use App\Services\StudentAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentDeviceIsActive
{
    public function __construct(private StudentAuditService $audit) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user('student');
        $deviceId = $request->session()->get('student_device_id');
        $device = $deviceId ? StudentDevice::query()->whereKey($deviceId)->where('student_id', $student?->id)->whereNull('revoked_at')->first() : null;
        if ($student === null || $device === null) {
            Auth::guard('student')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('student.login')->withErrors(['token' => 'أنهى تسجيل دخول من جهاز آخر جلستك. سجّل الدخول مجدداً للمتابعة.']);
        }
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
