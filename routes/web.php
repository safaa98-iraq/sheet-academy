<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\CurriculumController;
use App\Http\Controllers\Admin\GradeLevelController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\StudentProgressController;
use App\Http\Controllers\Admin\VideoUploadController;
use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\Auth\StudentTokenAuthController;
use App\Http\Controllers\Internal\VideoProcessingController;
use App\Http\Controllers\LessonAttachmentController;
use App\Http\Controllers\Student\DocumentViewerController;
use App\Http\Controllers\Student\LessonProgressController;
use App\Http\Controllers\Student\PlayerPreferenceController;
use App\Http\Controllers\Student\StudentActivityController;
use App\Http\Controllers\Student\StudentAgreementController;
use App\Http\Controllers\Student\StudentPlaylistController;
use App\Http\Controllers\Student\StudentViewLinkController;
use App\Http\Controllers\Student\VideoStreamController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAuditService;
use App\Support\LearningUi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest:student')->group(function (): void {
    Route::get('/login', fn () => view('auth.student-login'))->name('student.login');
    Route::post('/login', [StudentTokenAuthController::class, 'store'])->middleware('throttle:student-token-login')->name('student.login.store');
});

Route::middleware('guest:web')->group(function (): void {
    Route::get('/admin/login', fn () => view('auth.admin-login'))->name('admin.login');
    Route::post('/admin/login', [AdminSessionController::class, 'store'])->middleware('throttle:admin-login')->name('admin.login.store');
});

Route::middleware(['auth:student', 'student.token', 'student.device', 'student.agreement'])->group(function (): void {
    Route::get('/student-agreement', [StudentAgreementController::class, 'show'])->name('student.agreement.show');
    Route::post('/student-agreement', [StudentAgreementController::class, 'accept'])->name('student.agreement.accept');
    Route::post('/student/view-links', [StudentViewLinkController::class, 'issue'])->middleware('throttle:student-activity')->name('student.view-links.issue');
    Route::post('/student/view-links/close', [StudentViewLinkController::class, 'close'])->name('student.view-links.close');
    Route::post('/student/activity', [StudentActivityController::class, 'store'])->middleware('throttle:student-activity')->name('student.activity.store');
    Route::get('/learning', function (Request $request) {
        $student = $request->user('student');
        $courses = $student->accessibleCourses()->visibleForStudents()
            ->withCount(['lessons' => fn ($lessons) => $lessons->visibleForStudents()])
            ->with(['gradeLevel', 'lessons' => fn ($lessons) => $lessons->visibleForStudents()
                ->with(['progress' => fn ($progress) => $progress->where('student_id', $student->id)->latest('updated_at'), 'attachments'])])
            ->get();

        $courseCards = $courses->map(fn (Course $course): array => LearningUi::course($course, $student))->all();
        $recent = collect($courseCards)->flatMap(fn (array $course): array => collect($course['lessons'])->map(fn (array $lesson): array => ['course' => $course, 'lesson' => $lesson])->all())
            ->filter(fn (array $item): bool => $item['lesson']['position_seconds'] > 0 && $item['lesson']['progress_updated_at'] !== null)
            ->sortByDesc(fn (array $item): string => $item['lesson']['progress_updated_at'])->first();
        $resumeCourse = $recent['course'] ?? ($courseCards[0] ?? null);
        $resumeLesson = $recent['lesson'] ?? ($resumeCourse ? (collect($resumeCourse['lessons'])->firstWhere('completed', false) ?? ($resumeCourse['lessons'][0] ?? null)) : null);

        return view('student.learning', [
            'courseCards' => $courseCards,
            'progressSnapshot' => LearningUi::progressSnapshot($courseCards),
            'resumeCourse' => $resumeCourse,
            'resumeLesson' => $resumeLesson,
        ]);
    })->name('student.learning');
    Route::get('/course', function (Request $request) {
        $course = $request->user('student')->accessibleCourses()->visibleForStudents()->first();

        return $course ? redirect()->route('student.course.show', $course) : view('student.course', ['selectedCourse' => null]);
    })->name('student.course');
    Route::get('/course/{course:slug}', function (Request $request, Course $course) {
        abort_unless(Gate::forUser($request->user('student'))->allows('viewByStudent', $course), 404);

        $course->load(['lessons' => fn ($query) => $query->visibleForStudents()->with(['progress' => fn ($progress) => $progress->where('student_id', $request->user('student')->id)])]);

        $courseData = LearningUi::course($course, $request->user('student'));

        return view('student.course', ['selectedCourse' => $courseData, 'courseCards' => [$courseData], 'progressSnapshot' => LearningUi::progressSnapshot([$courseData])]);
    })->name('student.course.show');
    Route::get('/lesson', fn () => redirect()->route('student.course'))->name('student.lesson');
    Route::get('/lesson/{lesson}', function (Request $request, Lesson $lesson, StudentAuditService $audit) {
        abort_unless(Gate::forUser($request->user('student'))->allows('viewByStudent', $lesson), 404);

        $audit->record($request->user('student'), 'lesson_opened', $request, ['lesson_id' => $lesson->id]);
        $document = $lesson->attachments()->whereIn('mime_type', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->first();
        if (in_array($lesson->type, ['document', 'pdf', 'image'], true) && $document !== null) {
            return redirect()->route('student.documents.show', ['attachment' => $document, 'view' => $request->query('view')]);
        }

        $course = $lesson->course->load(['lessons' => fn ($query) => $query->visibleForStudents()->with(['progress' => fn ($progress) => $progress->where('student_id', $request->user('student')->id)])]);
        $selectedCourse = LearningUi::course($course, $request->user('student'));

        return view(in_array($lesson->type, ['document', 'pdf', 'image'], true) ? 'student.viewer' : 'student.lesson', [
            'lessonData' => LearningUi::lesson($selectedCourse, (string) $lesson->id), 'curriculum' => $selectedCourse['lessons'],
            'selectedCourse' => $selectedCourse, 'courseCards' => [$selectedCourse],
            'progressSnapshot' => LearningUi::progressSnapshot([$selectedCourse]),
        ]);
    })->middleware('student.view-link')->name('student.lesson.show');
    Route::get('/viewer', fn () => redirect()->route('student.learning'))->name('student.viewer');
    Route::get('/playlist', [StudentPlaylistController::class, 'index'])->name('student.playlist');
    Route::post('/student/playlists', [StudentPlaylistController::class, 'create'])->name('student.playlists.create');
    Route::post('/student/progress/{lesson}', [LessonProgressController::class, 'store'])->middleware('throttle:student-progress')->name('student.progress.store');
    Route::post('/student/playlists/items', [StudentPlaylistController::class, 'store'])->middleware('throttle:student-activity')->name('student.playlist-items.store');
    Route::delete('/student/playlists/items/{item}', [StudentPlaylistController::class, 'destroy'])->name('student.playlist-items.destroy');
    Route::patch('/student/playlists/{playlist}/order', [StudentPlaylistController::class, 'reorder'])->name('student.playlists.order');
    Route::get('/progress', function (Request $request) {
        $courses = $request->user('student')->accessibleCourses()->visibleForStudents()->with(['gradeLevel', 'lessons' => fn ($query) => $query->visibleForStudents()->with(['progress' => fn ($progress) => $progress->where('student_id', $request->user('student')->id), 'attachments'])])->get();

        $student = $request->user('student');

        $courseCards = $courses->map(fn (Course $course): array => LearningUi::course($course, $student))->all();
        $lessons = collect($courseCards)->flatMap(fn (array $course): array => $course['lessons']);

        return view('student.progress', [
            'courseCards' => $courseCards,
            'progressSnapshot' => LearningUi::progressSnapshot($courseCards),
            'progressTotals' => [
                'completed_lessons' => $lessons->where('completed', true)->count(),
                'watched_seconds' => $lessons->sum('watched_seconds'),
                'completed_courses' => collect($courseCards)->where('percentage', 100)->count(),
                'last_activity' => $lessons->pluck('progress_updated_at')->filter()->sortDesc()->first(),
            ],
        ]);
    })->name('student.progress');
    Route::post('/logout', [StudentTokenAuthController::class, 'destroy'])->name('student.logout');
    Route::put('/player-preferences', [PlayerPreferenceController::class, 'update'])->name('student.player-preferences.update');
    Route::get('/lessons/{lesson}/video-status', [VideoStreamController::class, 'status'])->middleware(['student.view-link', 'throttle:student-media'])->name('student.lessons.video-status');
    Route::get('/videos/{video}/session', [VideoStreamController::class, 'session'])->middleware(['student.view-link', 'throttle:student-media'])->name('student.video.session');
    Route::get('/videos/{video}/student/{student}/key', [VideoStreamController::class, 'key'])->middleware(['signed', 'student.view-link', 'throttle:student-media'])->name('student.video.key');
    Route::get('/videos/{video}/student/{student}/{asset}', [VideoStreamController::class, 'asset'])->where('asset', '.*')->middleware(['signed', 'student.view-link', 'throttle:student-media'])->name('student.video.asset');
    Route::get('/documents/{attachment}', [DocumentViewerController::class, 'show'])->middleware(['student.view-link', 'throttle:student-media'])->name('student.documents.show');
    Route::get('/documents/{attachment}/session', [DocumentViewerController::class, 'refresh'])->middleware(['student.view-link', 'throttle:student-media'])->name('student.documents.refresh');
    Route::get('/documents/{attachment}/pages/{page}', [DocumentViewerController::class, 'page'])->whereNumber('page')->middleware(['signed', 'student.view-link', 'throttle:student-media'])->name('student.documents.page');
    Route::get('/lesson-attachments/{attachment}/download', [DocumentViewerController::class, 'show'])->middleware(['student.view-link', 'throttle:student-media'])->name('student.lesson-attachments.download');
});

Route::middleware(['auth:web', 'admin.active'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');
    Route::get('/', function (Request $request) {
        abort_unless($request->user('web')->hasPermissionTo('dashboard.view'), 403);

        return view('admin.dashboard', [
            'studentsCount' => Student::count(),
            'coursesCount' => Course::count(),
            'activeStudents' => Student::where('status', 'active')->count(),
            'adminsCount' => User::count(),
        ]);
    })->name('dashboard');

    Route::middleware('permission:students.view')->group(function (): void {
        Route::get('/students', [StudentController::class, 'index'])->name('students.index');
        Route::get('/students/create', [StudentController::class, 'create'])->middleware('permission:students.manage')->name('students.create');
        Route::get('/students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('students.show');
        Route::get('/student-progress', [StudentProgressController::class, 'index'])->name('student-progress');
        Route::get('/student-progress/export.csv', [StudentProgressController::class, 'export'])->name('student-progress.export');
        Route::get('/student-progress/print', [StudentProgressController::class, 'printable'])->name('student-progress.print');
        Route::get('/student-progress/{student}', [StudentProgressController::class, 'show'])->whereNumber('student')->name('student-progress.show');
    });
    Route::middleware('permission:students.manage')->group(function (): void {
        Route::post('/students', [StudentController::class, 'store'])->name('students.store');
        Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('students.edit');
        Route::put('/students/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::delete('/students/{student}', [StudentController::class, 'destroy'])->name('students.destroy');
        Route::post('/students/{student}/token/reveal', [StudentController::class, 'revealToken'])->name('students.token.reveal');
        Route::post('/students/{student}/token', [StudentController::class, 'regenerate'])->name('students.token');
        Route::patch('/students/{student}/status', [StudentController::class, 'status'])->name('students.status');
    });

    Route::middleware('permission:admins.manage')->group(function (): void {
        Route::resource('admins', AdminUserController::class)->except(['destroy']);
        Route::delete('/admins/{admin}', [AdminUserController::class, 'destroy'])->name('admins.destroy');
        Route::get('/roles', [RolePermissionController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RolePermissionController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])->name('roles.permissions');
        Route::delete('/roles/{role}', [RolePermissionController::class, 'destroy'])->name('roles.destroy');
    });

    Route::resource('grade-levels', GradeLevelController::class)->only(['index'])->middleware('permission:courses.view');
    Route::resource('grade-levels', GradeLevelController::class)->except(['index', 'show'])->middleware('permission:courses.manage');

    Route::patch('/courses/order', [CourseController::class, 'reorder'])->middleware('permission:courses.manage')->name('courses.order');
    Route::get('/courses/{course}/curriculum', [CurriculumController::class, 'index'])->middleware('permission:courses.manage')->name('courses.curriculum');
    Route::put('/courses/{course}/students', [CourseController::class, 'syncStudents'])->middleware('permission:courses.manage')->name('courses.students.sync');
    Route::post('/courses/{course}/curriculum', [CurriculumController::class, 'store'])->middleware('permission:courses.manage')->name('courses.curriculum.store');
    Route::patch('/courses/{course}/curriculum/order', [CurriculumController::class, 'reorder'])->middleware('permission:courses.manage')->name('courses.curriculum.order');
    Route::get('/courses/{course}/preview', [CurriculumController::class, 'preview'])->middleware('permission:courses.view')->name('courses.preview');
    Route::put('/curriculum-nodes/{node}', [CurriculumController::class, 'update'])->middleware('permission:courses.manage')->name('curriculum-nodes.update');
    Route::delete('/curriculum-nodes/{node}', [CurriculumController::class, 'destroy'])->middleware('permission:courses.manage')->name('curriculum-nodes.destroy');
    Route::get('/lessons', [LessonController::class, 'index'])->middleware('permission:courses.view')->name('lessons.index');
    Route::get('/videos/{video}/preview-session', [VideoUploadController::class, 'previewSession'])->middleware('permission:courses.view')->name('videos.preview-session');
    Route::get('/videos/{video}/preview-key', [VideoUploadController::class, 'previewKey'])->middleware(['permission:courses.view', 'signed'])->name('videos.preview-key');
    Route::get('/videos/{video}/preview/{asset}', [VideoUploadController::class, 'previewAsset'])->where('asset', '.*')->middleware(['permission:courses.view', 'signed'])->name('videos.preview-asset');
    Route::post('/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store'])->middleware('permission:courses.manage')->name('lessons.attachments.store');
    Route::options('/lessons/{lesson}/video-uploads', [VideoUploadController::class, 'options'])->middleware('permission:courses.manage')->name('lessons.video-uploads.options');
    Route::post('/lessons/{lesson}/video-uploads', [VideoUploadController::class, 'start'])->middleware(['permission:courses.manage', 'throttle:video-upload'])->name('lessons.video-uploads.start');
    Route::match(['HEAD'], '/video-uploads/{upload}', [VideoUploadController::class, 'head'])->middleware('permission:courses.manage')->name('video-uploads.head');
    Route::patch('/video-uploads/{upload}', [VideoUploadController::class, 'chunk'])->middleware(['permission:courses.manage', 'throttle:video-upload'])->name('video-uploads.chunk');
    Route::get('/lessons/{lesson}/video-status', [VideoUploadController::class, 'videoStatus'])->middleware('permission:courses.view')->name('lessons.video-status');
    Route::post('/lessons/{lesson}/video-retry', [VideoUploadController::class, 'retry'])->middleware('permission:courses.manage')->name('lessons.video-retry');
    Route::put('/lessons/{lesson}/video-resolutions', [VideoUploadController::class, 'resolutions'])->middleware('permission:courses.manage')->name('lessons.video-resolutions');
    Route::delete('/lessons/{lesson}/video-resolutions/{resolution}', [VideoUploadController::class, 'deleteResolution'])->whereNumber('resolution')->middleware('permission:courses.manage')->name('lessons.video-resolutions.delete');
    Route::delete('/lesson-attachments/{attachment}', [LessonAttachmentController::class, 'destroy'])->middleware('permission:courses.manage')->name('lesson-attachments.destroy');
    Route::get('/lesson-attachments/{attachment}', [LessonAttachmentController::class, 'admin'])->middleware('permission:courses.view')->name('lesson-attachments.show');
    Route::resource('courses', CourseController::class)->middleware('permission:courses.view')->only(['index']);
    Route::middleware('permission:courses.manage')->resource('courses', CourseController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::middleware('permission:courses.manage')->resource('courses.lessons', LessonController::class)->only(['create', 'store']);
    Route::middleware('permission:courses.manage')->resource('lessons', LessonController::class)->only(['edit', 'update', 'destroy']);
    Route::get('/activity', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('activity');
    Route::get('/notifications/unread-count', fn (Request $request) => response()->json(['count' => $request->user('web')->unreadNotifications()->count()]))
        ->middleware('permission:audit.view')->name('notifications.unread-count');
});

Route::post('/internal/video-processing', [VideoProcessingController::class, 'update'])->middleware('throttle:internal-video-callback')->name('internal.video-processing.update');

Route::get('/offline', fn () => response()->file(public_path('offline.html')))->name('offline');
