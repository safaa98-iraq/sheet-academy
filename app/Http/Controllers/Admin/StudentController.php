<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Http\Requests\Admin\UpdateStudentStatusRequest;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\StudentAuditService;
use App\Services\StudentTokenService;
use App\Support\LearningUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, StudentTokenService $tokens): View
    {
        $this->authorize('viewAny', Student::class);
        $students = Student::query()
            ->with(['tokens' => fn ($query) => $query->latest('id')->limit(1)])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($students) => $students->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $visibleTokens = $request->user('web')->hasPermissionTo('students.manage') ? $students->getCollection()->mapWithKeys(fn (Student $student): array => [$student->id => $student->tokens->first() ? $tokens->reveal($student->tokens->first()) : null])->all() : [];

        return view('admin.students.index', ['visibleTokens' => $visibleTokens, 'students' => $students, 'issuedToken' => $request->user('web')->hasPermissionTo('students.manage') ? session()->pull('issued_student_token') : null]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $this->authorize('create', Student::class);

        return view('admin.students.create', ['courses' => Course::with('gradeLevel')->orderBy('title')->get(), 'gradeLevels' => GradeLevel::orderBy('position')->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreStudentRequest $request, StudentTokenService $tokens): RedirectResponse
    {
        $this->authorize('create', Student::class);
        $student = DB::transaction(function () use ($request, $tokens): Student {
            $data = $request->validated();
            $student = Student::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'college' => $data['college'] ?? null, 'phone' => $data['phone'] ?? null,
                'study_grade_level_id' => $data['study_grade_level_id'] ?? ($data['grade_level_id'] ?? null),
                'subscription_expires_at' => $this->subscriptionEnd($data),
                'status' => 'active',
                'created_by' => $request->user('web')->id,
                'grade_level_id' => null,
            ]);
            $student->courses()->sync($data['course_ids'] ?? []);
            $issued = $tokens->issue($student, $request->user('web'), isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : $student->subscription_expires_at, isset($data['device_limit']) ? (int) $data['device_limit'] : null);
            session()->flash('issued_student_token', ['student' => $student->name, 'token' => $issued['token']]);

            return $student;
        });

        return redirect()->route('admin.students.index')->with('status', 'تم إنشاء حساب الطالب؛ يمكن نسخ رمز الدخول من الجدول في أي وقت.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Student $student, Request $request, StudentTokenService $tokens): View
    {
        $this->authorize('view', $student);

        $accessibleCourses = $student->accessibleCourses()->with(['gradeLevel', 'lessons' => fn ($query) => $query->visibleForStudents()->with([
            'progress' => fn ($progress) => $progress->where('student_id', $student->id), 'attachments', 'video',
        ])])->orderBy('position')->get();
        $courseProgress = $accessibleCourses->mapWithKeys(function (Course $course) use ($student): array {
            $data = LearningUi::course($course, $student);

            return [$course->id => [
                'percentage' => $data['percentage'],
                'completed' => collect($data['lessons'])->where('completed', true)->count(),
                'total' => count($data['lessons']),
            ]];
        });

        return view('admin.students.show', ['accessibleCourses' => $accessibleCourses, 'courseProgress' => $courseProgress, 'visibleToken' => $request->user('web')->hasPermissionTo('students.manage') && ($latestToken = $student->tokens()->latest('id')->first()) ? $tokens->reveal($latestToken) : null, 'student' => $student->load(['gradeLevel', 'studyGradeLevel', 'courses', 'devices' => fn ($query) => $query->latest('last_seen_at'), 'tokens' => fn ($query) => $query->latest()])]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Student $student): View
    {
        $this->authorize('update', $student);

        return view('admin.students.edit', ['student' => $student->load(['courses', 'tokens' => fn ($query) => $query->latest('id')]), 'courses' => Course::with('gradeLevel')->orderBy('title')->get(), 'gradeLevels' => GradeLevel::orderBy('position')->get()]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStudentRequest $request, Student $student): RedirectResponse
    {
        $this->authorize('update', $student);
        $data = $request->validated();
        DB::transaction(function () use ($student, $data): void {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $student->fill(['name' => $data['name'], 'email' => $data['email'] ?? null, 'grade_level_id' => null,
                'college' => $data['college'] ?? $student->college, 'phone' => $data['phone'] ?? $student->phone,
                'study_grade_level_id' => $data['study_grade_level_id'] ?? $student->study_grade_level_id,
                'subscription_expires_at' => array_key_exists('subscription_duration', $data) ? $this->subscriptionEnd($data, $student->subscription_expires_at) : $student->subscription_expires_at]);
            if ($student->isDirty('subscription_expires_at')) {
                $student->forceFill(['subscription_expiry_notified_at' => null, 'subscription_reminded_at' => null, 'subscription_reminder_for' => null]);
            }
            $subscriptionChanged = $student->isDirty('subscription_expires_at');
            $student->save();
            if ($subscriptionChanged) {
                $student->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->update(['expires_at' => $student->subscription_expires_at, 'expiry_notified_at' => null]);
            }
            $student->courses()->sync($data['course_ids'] ?? []);
        });

        return redirect()->route('admin.students.index')->with('status', 'تم تحديث بيانات الطالب.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student): RedirectResponse
    {
        $this->authorize('delete', $student);
        $student->delete();

        return redirect()->route('admin.students.index')->with('status', 'تم حذف حساب الطالب.');
    }

    private function subscriptionEnd(array $data, ?Carbon $currentExpiry = null): ?Carbon
    {
        $duration = $data['subscription_duration'] ?? 'unlimited';
        if ($duration === 'custom' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $data['subscription_expires_at']) === 1) {
            if ($currentExpiry?->copy()->timezone('Asia/Baghdad')->toDateString() === $data['subscription_expires_at']) {
                return $currentExpiry;
            }

            return Carbon::parse($data['subscription_expires_at'], 'Asia/Baghdad')->endOfDay()->utc();
        }

        return match ($duration) {
            'unlimited' => null,
            'custom' => Carbon::parse($data['subscription_expires_at']),
            default => now()->addDays((int) $duration),
        };
    }

    public function revealToken(Student $student, StudentTokenService $tokens, Request $request): JsonResponse
    {
        $this->authorize('update', $student);

        $record = $student->tokens()->latest('id')->first();
        $plain = $record ? $tokens->reveal($record) : null;
        app(StudentAuditService::class)->record($student, 'token_revealed_by_admin', $request, ['actor_id' => $request->user('web')->id]);

        return $plain !== null ? response()->json(['token' => $plain])->header('Cache-Control', 'private, no-store')
            : response()->json(['message' => 'الرمز القديم محفوظ كتجزئة فقط. استبدله من صفحة التجديد مرة واحدة ليصبح قابلاً للنسخ.'], 422)->header('Cache-Control', 'private, no-store');
    }

    public function regenerate(Student $student, StudentTokenService $tokens, Request $request): RedirectResponse
    {
        $this->authorize('update', $student);
        if ($student->status !== 'active') {
            return back()->withErrors(['token' => 'أعد تفعيل حساب الطالب أولاً لإصدار رمز جديد.']);
        }
        $data = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
            'device_limit' => ['sometimes', 'required', 'integer', 'in:1'],
        ], [
            'expires_at.date' => 'أدخل تاريخ انتهاء صالحاً.',
            'expires_at.after' => 'تاريخ انتهاء الرمز يجب أن يكون في المستقبل.',
            'device_limit.required' => 'حدد عدد الأجهزة المسموح بها.',
            'device_limit.integer' => 'عدد الأجهزة يجب أن يكون عدداً صحيحاً.',
            'device_limit.in' => 'يسمح بجلسة واحدة فقط لكل طالب.',
        ]);
        $deviceLimit = (int) ($data['device_limit'] ?? $student->tokens()->latest('id')->value('device_limit'));
        $issued = $tokens->issue($student, $request->user('web'),
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : ($student->subscriptionIsActive() ? $student->subscription_expires_at : null),
            deviceLimit: $deviceLimit > 0 ? $deviceLimit : null,
        );
        session()->flash('issued_student_token', ['student' => $student->name, 'token' => $issued['token']]);

        return redirect()->route('admin.students.index')->with('status', 'تم إصدار رمز جديد وإلغاء الرمز السابق. انسخه الآن.');
    }

    public function status(Student $student, UpdateStudentStatusRequest $request): RedirectResponse
    {
        $this->authorize('update', $student);
        $data = $request->validated();
        DB::transaction(function () use ($student, $data): void {
            $student->update(['status' => $data['status']]);
            if ($data['status'] !== 'active') {
                $student->devices()->whereNull('revoked_at')->update(['revoked_at' => now(), 'view_link_hash' => null]);
            }
            $student->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->update(['status' => $data['status']]);
        });

        return back()->with('status', match ($data['status']) {
            'frozen' => 'تم تجميد حساب الطالب.',
            'suspended' => 'تم إيقاف حساب الطالب.',
            default => 'تم تفعيل حساب الطالب. إذا كان رمز الدخول ملغى، أصدر رمزاً جديداً.',
        });
    }
}
