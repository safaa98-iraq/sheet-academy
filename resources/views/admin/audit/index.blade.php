<div>
    <!-- Nothing worth having comes easy. - Theodore Roosevelt -->
</div>
@extends('admin.layout')

@section('content')
@php
    $eventLabels = [
        'login_succeeded' => 'دخول ناجح', 'login_failed' => 'فشل رمز الدخول', 'logout' => 'تسجيل خروج',
        'multiple_device_login' => 'دخول من جهاز آخر', 'lesson_opened' => 'فتح درس',
        'watch_started' => 'بدء مشاهدة', 'watch_heartbeat' => 'متابعة مشاهدة', 'view_exited' => 'مغادرة المشاهدة',
        'document_opened' => 'فتح ملزمة', 'suspicious_devtools' => 'أدوات مطور', 'suspicious_copy' => 'محاولة نسخ',
        'suspicious_print' => 'محاولة طباعة', 'suspicious_watermark' => 'عبث بالبصمة', 'stale_view_link' => 'رابط قديم',
        'automatic_suspension' => 'إيقاف تلقائي',
    ];
@endphp
<div class="admin-head"><div><span class="eyebrow">المراقبة والنزاهة</span><h1>سجل نشاط الطلاب</h1><p class="muted">الأحداث، عناوين الاتصال، ونقاط المخالفات المسجلة.</p></div></div>
<form class="admin-form audit-filters" method="get" action="{{ route('admin.activity') }}">
    <label>الطالب<select name="student_id"><option value="">كل الطلاب</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected(($filters['student_id'] ?? '') == $student->id)>{{ $student->name }}</option>@endforeach</select></label>
    <label>نوع النشاط<select name="event"><option value="">كل الأحداث</option>@foreach($eventLabels as $value=>$label)<option value="{{ $value }}" @selected(($filters['event'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label>من تاريخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
    <label>إلى تاريخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
    <div class="audit-filter-actions"><button class="btn primary">تطبيق الفلاتر</button><a class="btn" href="{{ route('admin.activity') }}">مسح</a></div>
</form>
<section class="admin-form audit-table-wrap">
    <div class="audit-summary"><strong>{{ number_format($logs->total()) }} حدث</strong><span>الإيقاف التلقائي {{ config('audit.auto_suspend_score') > 0 ? 'مفعّل عند '.config('audit.auto_suspend_score').' نقاط' : 'متوقف' }}</span></div>
    <div class="table-scroll"><table class="audit-table"><thead><tr><th>الوقت</th><th>الطالب</th><th>النشاط</th><th>النقاط</th><th>الجهاز</th><th>عنوان IP</th><th>التفاصيل</th></tr></thead><tbody>
    @forelse($logs as $log)<tr><td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td><td>@if($log->student)<a href="{{ route('admin.students.show', $log->student) }}">{{ $log->student->name }}</a><small>#{{ $log->student_id }}</small>@else<small>غير معروف</small>@endif</td><td>{{ $eventLabels[$log->event] ?? $log->event }}</td><td><span class="badge {{ $log->points ? 'badge-warning' : '' }}">{{ $log->points }}</span></td><td title="{{ $log->user_agent }}">{{ $log->device?->device_name ?: \Illuminate\Support\Str::limit($log->user_agent, 42) }}</td><td dir="ltr">{{ $log->ip_address ?: '—' }}</td><td>{{ collect($log->metadata ?? [])->map(fn ($value, $key) => $key.': '.$value)->join(' · ') ?: '—' }}</td></tr>@empty<tr><td colspan="7" class="muted">لا توجد أحداث تطابق عوامل التصفية.</td></tr>@endforelse
    </tbody></table></div>
    {{ $logs->links() }}
</section>
@endsection
