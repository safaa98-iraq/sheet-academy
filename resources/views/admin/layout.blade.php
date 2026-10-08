@php
    $isPreview = false;
    $adminName = $isPreview ? 'د. أحمد علي' : auth('web')->user()->name;
    $navigation = [
        ['admin', 'الرئيسية', 'chart', 'admin.dashboard', 'dashboard.view'],
        ['grade-levels', 'المراحل الصفية', 'book', 'admin.grade-levels.index', 'courses.view'],
        ['lessons', 'المحاضرات والمحتوى', 'layers', 'admin.lessons.index', 'courses.view'],
        ['curriculum', 'المواد والكتب', 'book', 'admin.courses.index', 'courses.view'],
        ['students', 'إدارة الطلاب', 'user', 'admin.students.index', 'students.view'],
        ['student-progress', 'تقدم الطلاب', 'chart', 'admin.student-progress', 'students.view'],
        ['admins', 'الفريق والصلاحيات', 'shield', 'admin.admins.index', 'admins.manage'],
        ['activity', 'سجل النشاط', 'clock', 'admin.activity', 'audit.view'],
    ];
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>@include('components.head', ['title' => $title ?? 'مساحة الأستاذ'])</head>
<body class="admin-body" data-storage-scope="instructor">
    <button class="admin-backdrop" data-admin-nav-close aria-label="إغلاق القائمة" hidden></button>
    <aside class="instructor-sidebar" id="instructor-sidebar" aria-label="قائمة الأستاذ">
        <a class="instructor-brand" href="{{ route('admin.dashboard') }}"><span class="instructor-brand-mark"><x-icon name="book" /></span><span>عيادة التعلّم<small>LEARNING CLINIC</small></span></a>
        <div class="instructor-space"><span>مساحة الأستاذ</span><span class="badge">{{ $isPreview ? 'معاينة' : 'إدارة' }}</span></div>
        <nav class="instructor-nav">
            @foreach($navigation as [$key, $label, $icon, $routeName, $permission])
                @if(($isPreview && $key !== 'grade-levels') || (!$isPreview && $routeName && (!$permission || auth('web')->user()->can($permission))))
                    @php($isActive = $isPreview ? ($page === $key || ($key === 'curriculum' && $page === 'editor') || ($key === 'student-progress' && $page === 'student-detail')) : request()->routeIs($routeName, str_replace('.index', '.*', $routeName)))
                    <a class="{{ $isActive ? 'active' : '' }}" @if($isActive) aria-current="page" @endif href="{{ route($routeName) }}"><x-icon :name="$icon" /><span>{{ $label }}</span>@if($key === 'students' && $isPreview)<small>١٢٨</small>@endif</a>
                @endif
            @endforeach
        </nav>
        <div class="instructor-sidebar-bottom"><div class="instructor-tip"><x-icon name="book" /><b>التعلّم يبدأ بمحتوى رائع</b><p>رتّب دروسك، وتابع أثرها في رحلة طلابك.</p></div><a href="{{ route('student.login') }}"><x-icon name="arrow-left" /> دخول الطالب</a></div>
    </aside>
    <div class="instructor-workspace">
        <header class="instructor-topbar">
            <button class="icon-btn admin-menu-button" data-admin-nav-toggle aria-label="فتح قائمة الأستاذ" aria-controls="instructor-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
            <div class="instructor-breadcrumb">مساحة الأستاذ <span>/</span> <b>{{ $title ?? 'نظرة عامة' }}</b></div>
            <div class="instructor-topbar-actions"><button class="icon-btn" data-theme-toggle aria-label="تبديل الوضع الليلي"><x-icon name="moon" /></button>
                @unless($isPreview)
                    @can('audit.view')<a class="icon-btn audit-alert-link" href="{{ route('admin.activity') }}" aria-label="تنبيهات النشاط"><x-icon name="bell"/><span data-audit-alert-count hidden>0</span></a>@endcan
                @endunless
                <span class="admin-topbar-divider"></span><span class="admin-avatar">أع</span><div class="instructor-profile"><b>{{ $adminName }}</b><small>أستاذ طب الأسنان</small></div>
                @unless($isPreview)<form method="post" action="{{ route('admin.logout') }}">@csrf<button class="icon-btn" title="تسجيل الخروج" aria-label="تسجيل الخروج"><x-icon name="logout" /></button></form>@endunless
            </div>
        </header>
        <main class="admin-page">
            @if(session('status'))<div class="status-msg" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
        <footer class="instructor-footer"><span>عيادة التعلّم · مساحة تصنع فرقاً</span><span>{{ $isPreview ? 'بيانات تجريبية · تحفظ تغييرات المعاينة على جهازك' : 'مساحة إدارة المحتوى والطلاب' }}</span></footer>
    </div>
    <div class="admin-toast" data-admin-toast role="status" hidden></div>
    <dialog class="admin-dialog" data-admin-dialog><form method="dialog" class="admin-dialog-close"><button class="icon-btn" aria-label="إغلاق"><x-icon name="close" /></button></form><div data-admin-dialog-content></div></dialog>
</body>
</html>
