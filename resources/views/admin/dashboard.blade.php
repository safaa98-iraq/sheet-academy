@extends('admin.layout')
@section('content')
<div class="admin-head dashboard-welcome">
    <div><span class="eyebrow"><span class="eyebrow-line"></span>مساحة الأستاذ</span><h1>مرحباً، {{ auth('web')->user()->name }}<span class="heading-dot">.</span></h1><p class="muted">رؤية واضحة لمنصتك، ومساحة أكبر لأثرِك.</p></div>
    @can('students.manage')<a class="btn primary" href="{{ route('admin.students.create') }}"><x-icon name="plus"/>إضافة طالب</a>@endcan
</div>
<section class="admin-grid dashboard-stats" aria-label="إحصائيات المنصة">
    @foreach([
        ['إجمالي الطلاب', $studentsCount, 'جميع الحسابات المسجلة', 'user'],
        ['الطلاب النشطون', $activeStudents, 'حساباتهم غير مجمدة', 'check'],
        ['المواد', $coursesCount, 'المحتوى التعليمي في المنصة', 'book'],
        ['الأدمنز', $adminsCount, 'يشمل حساب مدير المنصة', 'shield'],
    ] as [$label, $count, $description, $icon])
    <div class="admin-stat"><div class="stat-heading"><span>{{ $label }}</span><span class="stat-icon"><x-icon :name="$icon"/></span></div><b>{{ number_format($count) }}</b><small>{{ $description }}</small></div>
    @endforeach
</section>
<section class="dashboard-section">
    <div class="section-heading"><div><h2>من أين نبدأ اليوم؟</h2><p class="muted">أدواتك اليومية، بخطوة واحدة.</p></div><span class="badge">إدارة المنصة</span></div>
    <div class="dashboard-shortcuts">
        @foreach([
            ['courses.view', 'admin.courses.index', 'book', 'المواد والمحتوى', 'نظّم المواد، وامنح كل درس مكانه في المنهج.'],
            ['students.view', 'admin.students.index', 'user', 'إدارة الطلاب', 'تابع الحسابات والمواد المخصصة لكل طالب.'],
            ['students.view', 'admin.student-progress', 'chart', 'رحلة الطلاب', 'اطّلع على التقدّم ونقاط التوقف بالتفصيل.'],
            ['audit.view', 'admin.activity', 'shield', 'النشاط والحماية', 'راجع أحداث المنصة وتنبيهات حماية المحتوى.'],
            ['admins.manage', 'admin.admins.index', 'layers', 'الفريق والصلاحيات', 'إدارة فريق العمل وصلاحيات الوصول.'],
        ] as [$permission, $route, $icon, $label, $description])
        @can($permission)<a class="dashboard-shortcut" href="{{ route($route) }}"><span class="shortcut-icon"><x-icon :name="$icon"/></span><div><h3>{{ $label }}</h3><p>{{ $description }}</p></div><x-icon name="arrow-left"/></a>@endcan
        @endforeach
    </div>
</section>
<div class="dashboard-note"><span class="note-icon"><x-icon name="tooth"/></span><div><h3>محتوى أفضل. تجربة تعلّم أعمق.</h3><p class="muted">ابدأ بتنظيم محاضراتك، ثم تابع كيف يتقدّم طلابك، درساً بعد درس.</p></div></div>
@endsection
