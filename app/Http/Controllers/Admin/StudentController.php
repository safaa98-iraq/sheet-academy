<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Http\Requests\Admin\UpdateStudentStatusRequest;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Student;
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
    public function index(Request $request): View
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

        return view('admin.students.index', ['students' => $students, 'issuedToken' => session()->pull('issued_student_token')]);
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
                'status' => 'active',
                'created_by' => $request->user('web')->id,
                'grade_level_id' => $data['grade_level_id'] ?? null,
            ]);
            $student->courses()->sync(($data['access_type'] ?? 'courses') === 'grade' ? [] : ($data['course_ids'] ?? []));
            $issued = $tokens->issue($student, $request->user('web'), isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null, isset($data['device_limit']) ? (int) $data['device_limit'] : null);
            session()->flash('issued_student_token', ['student' => $student->name, 'token' => $issued['token']]);

            return $student;
        });

        return redirect()->route('admin.students.index')->with('status', 'تم إنشاء حساب الطالب. يمكنك عرض رمز الوصول ونسخه من ملف الطالب.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Student $student): View
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

        return view('admin.students.show', ['accessibleCourses' => $accessibleCourses, 'courseProgress' => $courseProgress, 'student' => $student->load(['gradeLevel', 'courses', 'devices' => fn ($query) => $query->latest('last_seen_at'), 'tokens' => fn ($query) => $query->latest()])]);
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
            $student->update(['name' => $data['name'], 'email' => $data['email'] ?? null, 'grade_level_id' => $data['grade_level_id'] ?? null]);
            $student->courses()->sync(($data['access_type'] ?? 'courses') === 'grade' ? [] : ($data['course_ids'] ?? []));
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

    public function revealToken(Student $student): JsonResponse
    {
        $this->authorize('update', $student);
        $token = $student->tokens()->latest('id')->first();
        if ($token === null || $token->encrypted_token === null) {
            return response()->json(['message' => 'هذا الرمز القديم محفوظ كتجزئة فقط ولا يمكن عرضه. أصدر توكناً جديداً ليصبح قابلاً للعرض والنسخ.'], 422)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['token' => $token->encrypted_token])->header('Cache-Control', 'private, no-store');
    }

    public function regenerate(Student $student, StudentTokenService $tokens, Request $request): RedirectResponse
    {
        $this->authorize('update', $student);
        if ($student->status !== 'active') {
            return back()->withErrors(['token' => 'أعد تفعيل حساب الطالب أولاً لإصدار رمز جديد.']);
        }
        $data = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
            'device_limit' => ['sometimes', 'required', 'integer', 'between:1,10'],
        ], [
            'expires_at.date' => 'أدخل تاريخ انتهاء صالحاً.',
            'expires_at.after' => 'تاريخ انتهاء الرمز يجب أن يكون في المستقبل.',
            'device_limit.required' => 'حدد عدد الأجهزة المسموح بها.',
            'device_limit.integer' => 'عدد الأجهزة يجب أن يكون عدداً صحيحاً.',
            'device_limit.between' => 'عدد الأجهزة يجب أن يكون بين 1 و10.',
        ]);
        $deviceLimit = (int) ($data['device_limit'] ?? $student->tokens()->latest('id')->value('device_limit'));
        $issued = $tokens->issue($student, $request->user('web'),
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
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
            $student->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->update(['status' => $data['status']]);
        });

        return back()->with('status', match ($data['status']) {
            'frozen' => 'تم تجميد حساب الطالب.',
            'suspended' => 'تم إيقاف حساب الطالب.',
            default => 'تم تفعيل حساب الطالب. إذا كان رمز الدخول ملغى، أصدر رمزاً جديداً.',
        });
    }
}
