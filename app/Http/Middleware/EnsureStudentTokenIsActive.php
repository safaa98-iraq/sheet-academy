<?php

namespace App\Http\Middleware;

use App\Models\StudentDevice;
use App\Models\StudentToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentTokenIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user('student');
        $tokenId = $request->session()->get('student_token_id');
        $token = $tokenId ? StudentToken::query()->whereKey($tokenId)->where('student_id', $student?->id)->where('status', 'active')->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->first() : null;

        if ($student === null || $student->status !== 'active' || ! $student->subscriptionIsActive() || $token === null) {
            StudentDevice::query()->whereKey($request->session()->get('student_device_id'))
                ->where('student_id', $student?->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'view_link_hash' => null]);
            Auth::guard('student')->logout();
            $request->session()->forget('student_token_id');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($student !== null && ! $student->subscriptionIsActive()) {
                $message = 'انتهت مدة اشتراكك. تواصل مع الأستاذ لتجديدها.';

                return $request->expectsJson() ? response()->json(['message' => $message], 423) : redirect()->route('student.login')->withErrors(['token' => $message]);
            }

            return redirect()->route('student.login')->withErrors(['token' => 'انتهت صلاحية الجلسة. أدخل رمز الوصول مجدداً.']);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
