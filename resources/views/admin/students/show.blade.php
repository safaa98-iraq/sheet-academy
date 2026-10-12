@extends('admin.layout', ['title' => 'ملف الطالب'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">إدارة الطلاب / ملف الطالب</span><h1>تفاصيل الطالب</h1><p class="muted">البيانات الأساسية والاشتراك أمامك؛ افتح الإجراءات الإضافية عند الحاجة.</p></div><a class="btn" href="{{ route('admin.students.index') }}"><x-icon name="arrow-right"/>العودة إلى الطلاب</a></div>
<section class="account-hero professor-student-hero"><div class="account-identity"><span class="account-avatar" aria-hidden="true">{{ mb_substr($student->name, 0, 1) }}</span><div><span class="eyebrow">حساب طالب · {{ $student->id }}</span><h2>{{ $student->name }}</h2><p class="muted">{{ $student->college ?: 'الكلية غير مسجلة' }} · {{ $student->studyGradeLevel?->name ?? $student->gradeLevel?->name ?? 'المرحلة غير مسجلة' }}</p></div></div><span @class(['account-status', 'is-inactive' => $student->status !== 'active'])><span class="status-dot"></span>{{ ['active'=>'نشط','frozen'=>'مجمّد','suspended'=>'موقوف'][$student->status] ?? $student->status }}</span></section>
@can('update', $student)<div class="account-quick-actions"><a class="btn primary" href="{{ route('admin.students.edit', $student) }}"><x-icon name="edit"/>تعديل البيانات والمواد</a><a class="btn" href="{{ route('admin.students.edit', $student) }}#subscription"><x-icon name="clock"/>تجديد الاشتراك</a><a class="btn" href="{{ route('admin.student-progress.show', $student) }}"><x-icon name="chart"/>متابعة التقدّم</a></div>@endcan
<div class="account-columns">
<section class="account-card"><div class="account-card-heading"><x-icon name="user"/><h2>بيانات الطالب</h2></div><dl class="account-details"><div><dt>الاسم الكامل</dt><dd>{{ $student->name }}</dd></div><div><dt>الكلية</dt><dd>{{ $student->college ?: 'غير مسجلة' }}</dd></div><div><dt>الهاتف الشخصي</dt><dd dir="ltr">{{ $student->phone ?: 'غير مسجل' }}</dd></div><div><dt>البريد الإلكتروني</dt><dd dir="ltr">{{ $student->email ?: 'غير مسجل' }}</dd></div><div><dt>المرحلة الدراسية</dt><dd>{{ $student->studyGradeLevel?->name ?? $student->gradeLevel?->name ?? 'غير مسجلة' }}</dd></div><div><dt>تاريخ الانضمام</dt><dd>{{ $student->created_at->copy()->timezone('Asia/Baghdad')->format('Y-m-d') }}</dd></div></dl></section>
<section class="account-card account-subscription"><div class="account-card-heading"><x-icon name="shield"/><h2>الاشتراك والوصول</h2></div><p class="account-expiry">{{ $student->subscription_expires_at?->copy()->timezone('Asia/Baghdad')->format('Y-m-d') ?? 'بدون انتهاء' }}</p><p class="muted">{{ $student->subscriptionIsActive() ? 'الاشتراك متاح' : 'انتهى الاشتراك' }} · بتوقيت بغداد</p><dl class="account-details compact"><div><dt>المواد المخصصة</dt><dd>{{ $accessibleCourses->count() }}</dd></div><div><dt>نقاط المخالفات</dt><dd>{{ $student->violation_score }}</dd></div><div><dt>آخر نشاط</dt><dd>{{ $student->devices->first()?->last_seen_at?->copy()->timezone('Asia/Baghdad')->format('Y-m-d H:i') ?? 'لم يسجل الدخول بعد' }}</dd></div></dl>@can('update', $student)<x-step-tip>لتجديد الاشتراك اختر «تجديد الاشتراك»، وحدد التاريخ من التقويم، ثم احفظ. تجميد الحساب يمنع الدخول حتى إن بقي الاشتراك سارياً.</x-step-tip>@else<x-step-tip>هذه صلاحية مشاهدة بيانات الطالب. تعديل البيانات والوصول يحتاج إلى صلاحية إدارة الطلاب.</x-step-tip>@endcan</section>
</div>
<section class="admin-form">
    <div class="admin-head"><h2>المواد والتقدّم الدراسي</h2><a class="btn small" href="{{ route('admin.student-progress.show', $student) }}"><x-icon name="chart"/><span>عرض تقدّم الطالب</span></a></div>
    <p class="muted">الطالب يرى المواد المنشورة المسجّل بها فقط. أضف المواد أو أزلها من «تعديل البيانات والمواد».</p>
    <p><b>نطاق الوصول:</b> {{ $student->grade_level_id ? 'المرحلة كاملة: '.$student->gradeLevel?->name : 'مواد محددة فقط' }}</p>
    <p class="muted">نسبة التقدّم تشمل الإنجاز الجزئي للدروس المتاحة للطالب، وتُحسب بالطريقة نفسها في حسابه.</p>
    <div class="student-course-progress-list">
        @forelse($accessibleCourses as $course)
            @php($progress = $courseProgress[$course->id])
            <article class="student-course-progress">
                <div class="student-course-progress-heading"><div><h3><x-icon name="book"/> {{ $course->title }}</h3><small class="muted">{{ $course->gradeLevel?->name ?? 'بدون مرحلة' }}</small></div><span class="badge">{{ !$course->isVisibleToStudents() ? 'غير متاحة للطالب حالياً' : ($progress['total'] === 0 ? 'لا توجد دروس منشورة' : ($progress['percentage'] === 100 ? 'مكتملة' : ($progress['percentage'] > 0 ? 'قيد التعلّم' : 'لم يبدأ بعد'))) }}</span></div>
                @if($course->isVisibleToStudents())
                    <x-progress-bar :value="$progress['percentage']" :label="'تقدّم الطالب في '.$course->title"/>
                    <div class="student-course-progress-meta"><strong>{{ $progress['percentage'] }}٪</strong><span>{{ $progress['completed'] }} من {{ $progress['total'] }} دروس مكتملة</span></div>
                @else
                    <p class="muted">تظهر نسبة التقدّم عند إتاحة المادة للطالب.</p>
                @endif
            </article>
        @empty
            <p class="muted">لا توجد مواد مرتبطة بهذا الحساب بعد.</p>
        @endforelse
    </div>
</section>
<details class="account-disclosure"><summary><x-icon name="key"/>إجراءات الدخول وأمان الحساب<span class="muted">التفعيل، التجميد، ورمز الدخول</span></summary>@include('admin.students.actions', ['student' => $student])<section class="admin-form">
    <h2>سجل رموز الدخول</h2><p class="muted">انتهاء صلاحية الرمز يختلف عن تجميد الحساب. عند انتهاء الصلاحية، أصدر رمزاً جديداً للحساب النشط.</p>
    @forelse($student->tokens as $token)
        <div class="student-token-history"><span class="badge">{{ $token->expires_at?->isPast() ? 'منتهي الصلاحية' : (['active'=>'فعال','frozen'=>'مجمّد','suspended'=>'موقوف','revoked'=>'ملغى'][$token->status] ?? $token->status) }}</span><span>أُنشئ {{ $token->created_at->format('Y-m-d H:i') }}</span><span>الصلاحية: {{ $token->expires_at?->format('Y-m-d H:i') ?? 'بلا انتهاء' }}</span><span>الأجهزة المسموحة: {{ $token->device_limit }}</span></div>
    @empty<p class="muted">لم يصدر رمز دخول لهذا الطالب بعد.</p>@endforelse
</section>
</details>
<details class="account-disclosure"><summary><x-icon name="shield"/>الأجهزة والجلسات<span class="muted">تفاصيل المراقبة عند الحاجة</span></summary><section class="admin-form">
    <h2>الأجهزة والجلسات</h2><p class="muted">راجع آخر نشاط عند متابعة مشكلة دخول. ظهور أكثر من جلسة لا يعني وحده وجود مخالفة.</p>
    <div class="table-wrap student-device-table"><table><thead><tr><th>الحالة</th><th>عنوان IP</th><th>آخر نشاط</th><th>الجهاز والمتصفح</th></tr></thead><tbody>
    @forelse($student->devices as $device)<tr><td>{{ $device->revoked_at ? 'منتهية' : 'نشطة' }}</td><td dir="ltr">{{ $device->ip_address ?: '—' }}</td><td>{{ $device->last_seen_at?->format('Y-m-d H:i') ?: '—' }}</td><td class="student-device-name" dir="ltr">{{ $device->user_agent ?: $device->device_name }}</td></tr>@empty<tr><td colspan="4">لا توجد أجهزة مسجلة.</td></tr>@endforelse
    </tbody></table></div>
</section>
</details>
@can('delete', $student)<details class="account-disclosure account-danger"><summary><x-icon name="trash"/>حذف الحساب</summary>
<section class="admin-form"><h2>حذف حساب الطالب</h2><p class="muted">الحذف يزيل الحساب وتقدّمه نهائياً. إذا كنت تريد إيقاف الوصول مؤقتاً، استخدم التجميد بدلاً من الحذف.</p><form method="post" action="{{ route('admin.students.destroy', $student) }}" data-confirm="حذف حساب الطالب وتقدّمه نهائياً؟ لا يمكن التراجع عن هذا الإجراء.">@csrf @method('DELETE')<button class="btn student-delete"><x-icon name="trash"/><span>حذف حساب الطالب</span></button></form></section>
</details>
@endcan
@endsection
