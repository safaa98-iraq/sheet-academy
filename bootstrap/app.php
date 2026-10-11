<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureStudentAgreementAccepted;
use App\Http\Middleware\EnsureStudentDeviceIsActive;
use App\Http\Middleware\EnsureStudentTokenIsActive;
use App\Http\Middleware\EnsureStudentViewLinkIsCurrent;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: null,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->prepend(AddSecurityHeaders::class);
        $middleware->preventRequestForgery(except: ['internal/video-processing']);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin/*') || $request->is('admin') ? route('admin.login') : route('student.login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('admin/*') || $request->is('admin') ? route('admin.dashboard') : route('student.learning'));
        $middleware->alias([
            'admin.active' => EnsureAdminIsActive::class,
            'student.token' => EnsureStudentTokenIsActive::class,
            'student.device' => EnsureStudentDeviceIsActive::class,
            'student.view-link' => EnsureStudentViewLinkIsCurrent::class,
            'student.agreement' => EnsureStudentAgreementAccepted::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response): Response {
            $messages = [401 => 'يلزم تسجيل الدخول.', 403 => 'الوصول غير مسموح.', 404 => 'المحتوى غير متاح.', 419 => 'انتهت صلاحية الصفحة.', 429 => 'طلبات كثيرة؛ انتظر قليلاً.', 500 => 'تعذّر إكمال العملية.', 503 => 'المنصة قيد الصيانة.'];
            if ($response instanceof JsonResponse && isset($messages[$response->getStatusCode()])) {
                $data = $response->getData(true);
                if (isset($data['message']) && ! preg_match('/[\x{0600}-\x{06FF}]/u', $data['message'])) {
                    $data['message'] = $messages[$response->getStatusCode()];
                    $response->setData($data);
                }
            }
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

            return $response;
        });
    })->create();
