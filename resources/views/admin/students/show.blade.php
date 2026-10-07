@extends('admin.layout')
@section('content')
<div class="admin-head">
    <div><span class="eyebrow">إدارة الطلاب / ملف الطالب</span><h1>{{ $student->name }}</h1><p class="muted">{{ $student->email ?: 'لا يوجد بريد إلكتروني' }}</p></div>
    <a class="btn" href="{{ route('admin.students.index') }}"><x-icon name="arrow-right"/><span>العودة إلى الطلاب</span></a>
</div>
<section class="student-overview" aria-label="ملخص حساب الطالب">
    <div><span class="muted">حالة الحساب</span><strong>{{ ['active'=>'نشط','frozen'=>'مجمّد','suspended'=>'موقوف'][$student->status] ?? $student->status }}</strong></div>
    <div><span class="muted">المواد المسجّل بها</span><strong>{{ $accessibleCourses->count() }}</strong></div>
    <div><span class="muted">تاريخ الانضمام</span><strong>{{ $student->created_at->format('Y-m-d') }}</strong></div>
    <div><span class="muted">نقاط المخالفات</span><strong>{{ $student->violation_score }}</strong></div>
</section>
@include('admin.students.actions', ['student' => $student])
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
<section class="admin-form">
    <h2>الأجهزة والجلسات</h2><p class="muted">راجع آخر نشاط عند متابعة مشكلة دخول. ظهور أكثر من جلسة لا يعني وحده وجود مخالفة.</p>
    <div class="table-wrap student-device-table"><table><thead><tr><th>الحالة</th><th>عنوان IP</th><th>آخر نشاط</th><th>الجهاز والمتصفح</th></tr></thead><tbody>
    @forelse($student->devices as $device)<tr><td>{{ $device->revoked_at ? 'منتهية' : 'نشطة' }}</td><td dir="ltr">{{ $device->ip_address ?: '—' }}</td><td>{{ $device->last_seen_at?->format('Y-m-d H:i') ?: '—' }}</td><td class="student-device-name" dir="ltr">{{ $device->user_agent ?: $device->device_name }}</td></tr>@empty<tr><td colspan="4">لا توجد أجهزة مسجلة.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="admin-form">
    <h2>سجل رموز الدخول</h2><p class="muted">انتهاء صلاحية الرمز يختلف عن تجميد الحساب. عند انتهاء الصلاحية، أصدر رمزاً جديداً للحساب النشط.</p>
    @forelse($student->tokens as $token)
        <div class="student-token-history"><span class="badge">{{ $token->expires_at?->isPast() ? 'منتهي الصلاحية' : (['active'=>'فعال','frozen'=>'مجمّد','suspended'=>'موقوف','revoked'=>'ملغى'][$token->status] ?? $token->status) }}</span><span>أُنشئ {{ $token->created_at->format('Y-m-d H:i') }}</span><span>الصلاحية: {{ $token->expires_at?->format('Y-m-d H:i') ?? 'بلا انتهاء' }}</span><span>الأجهزة المسموحة: {{ $token->device_limit }}</span></div>
    @empty<p class="muted">لم يصدر رمز دخول لهذا الطالب بعد.</p>@endforelse
</section>
@can('delete', $student)
<section class="admin-form"><h2>حذف حساب الطالب</h2><p class="muted">الحذف يزيل الحساب وتقدّمه نهائياً. إذا كنت تريد إيقاف الوصول مؤقتاً، استخدم التجميد بدلاً من الحذف.</p><form method="post" action="{{ route('admin.students.destroy', $student) }}" data-confirm="حذف حساب الطالب وتقدّمه نهائياً؟ لا يمكن التراجع عن هذا الإجراء.">@csrf @method('DELETE')<button class="btn student-delete"><x-icon name="trash"/><span>حذف حساب الطالب</span></button></form></section>
@endcan
@endsection
