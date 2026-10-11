<?php

namespace App\Http\Middleware;

use App\Models\StudentDevice;
use App\Services\StudentAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentViewLinkIsCurrent
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
        $device = StudentDevice::query()->whereKey($request->session()->get('student_device_id'))
            ->where('student_id', $student?->id)->whereNull('revoked_at')->first();
        $viewLink = $request->query('view');
        if ($device === null || ! is_string($viewLink) || strlen($viewLink) < 100 || strlen($viewLink) > 1024
            || ! hash_equals((string) $device->view_link_hash, hash('sha256', $viewLink))) {
            $this->audit->record($student, 'stale_view_link', $request, ['path' => $request->path()], $device);

            abort(404);
        }
        $request->session()->put('active_view_link', $viewLink);

        return $next($request);
    }
}
