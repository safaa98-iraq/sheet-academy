<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Policies\LessonPolicy;
use App\Policies\StudentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('video-upload', fn (Request $request): Limit => Limit::perMinute(120)->by(($request->user('web')?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('internal-video-callback', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('student-activity', fn (Request $request): Limit => Limit::perMinute(30)->by(($request->user('student')?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('student-progress', fn (Request $request): Limit => Limit::perMinute(30)->by(($request->user('student')?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('student-media', fn (Request $request): Limit => Limit::perMinute(240)->by(($request->user('student')?->id ?? 'guest').'|'.$request->ip()));
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Lesson::class, LessonPolicy::class);
        foreach (['students.view', 'students.manage', 'courses.view', 'courses.manage', 'admins.manage', 'audit.view', 'settings.manage', 'dashboard.view'] as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermissionTo($permission));
        }
        RateLimiter::for('student-token-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by('student-token:'.$request->ip())->response(function (Request $request, array $headers) {
                $message = 'رمز الدخول غير صالح أو منتهي.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }
                $response = redirect()->route('student.login')->withErrors(['token' => $message]);
                $response->headers->add($headers);

                return $response;
            });
        });
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip())->response(function (Request $request, array $headers) {
                $message = 'بيانات الدخول غير صحيحة.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }
                $response = redirect()->route('admin.login')->withErrors(['email' => $message]);
                $response->headers->add($headers);

                return $response;
            });
        });
        Gate::before(function ($user): ?bool {
            return $user instanceof User && $user->is_super_admin ? true : null;
        });
    }
}
