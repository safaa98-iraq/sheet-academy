<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentAgreementAccepted
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user('student');
        if ($student !== null && ($student->content_agreement_accepted_at === null
            || $student->content_agreement_version !== config('audit.agreement_version'))
            && ! $request->routeIs('student.agreement.*', 'student.logout')) {
            return redirect()->route('student.agreement.show');
        }

        return $next($request);
    }
}
