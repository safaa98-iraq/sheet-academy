<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class StudentDeviceBindingService
{
    public const COOKIE_NAME = 'academy_student_device';

    public function credential(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE_NAME);

        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
    }

    public function matches(Student $student, Request $request): bool
    {
        $credential = $this->credential($request);

        return $student->bound_device_hash !== null && $credential !== null
            && hash_equals($student->bound_device_hash, hash('sha256', $credential));
    }

    public function cookie(string $credential, Request $request): Cookie
    {
        return cookie(self::COOKIE_NAME, $credential, 60 * 24 * 400, '/', null,
            secure: $request->isSecure() || (bool) config('session.secure'), httpOnly: true, sameSite: 'lax');
    }
}
