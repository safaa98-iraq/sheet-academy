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
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
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
    })->create();
