@php
    $isPreview = false;
    $adminName = $isPreview ? 'د. أحمد علي' : auth('web')->user()->name;
    $navigation = [
        ['admin', 'الرئيسية', 'chart', 'admin.dashboard', 'dashboard.view'],
        ['grade-levels', 'المراحل الصفية', 'book', 'admin.grade-levels.index', 'courses.view'],
        ['lessons', 'المحاضرات والمحتوى', 'layers', 'admin.lessons.index', 'courses.view'],
        ['curriculum', 'المواد والكتب', 'book', 'admin.courses.index', 'courses.view'],
        ['archive', 'الصفوف والمواد والدروس المؤرشفة', 'clock', 'admin.archive.index', 'courses.manage'],
        ['students', 'إدارة الطلاب', 'user', 'admin.students.index', 'students.view'],
        ['student-progress', 'تقدم الطلاب', 'chart', 'admin.student-progress', 'students.view'],
        ['admins', 'الفريق والصلاحيات', 'shield', 'admin.admins.index', 'admins.manage'],
        ['settings', 'إعدادات الموقع', 'settings', 'admin.settings.edit', 'settings.manage'],
        ['activity', 'سجل النشاط', 'clock', 'admin.activity', 'audit.view'],
    ];
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>@include('components.head', ['title' => $title ?? 'مساحة الأستاذ'])</head>
<body class="admin-body" data-storage-scope="instructor">
    <button class="admin-backdrop" data-admin-nav-close aria-label="إغلاق القائمة" hidden></button>
    <aside class="instructor-sidebar" id="instructor-sidebar" aria-label="قائمة الأستاذ">
        <a class="instructor-brand" href="{{ route('admin.dashboard') }}"><span class="instructor-brand-mark"><x-site-brand-mark/></span><span>{{ $siteBrand['name'] }}<small>{{ $siteBrand['tagline'] }}</small></span></a>
        <div class="instructor-space"><span>مساحة الأستاذ</span><span class="badge">{{ $isPreview ? 'معاينة' : 'إدارة' }}</span></div>
        <nav class="instructor-nav">
            @foreach($navigation as [$key, $label, $icon, $routeName, $permission])
                @if(($isPreview && $key !== 'grade-levels') || (!$isPreview && $routeName && (!$permission || auth('web')->user()->can($permission))))
                    @php($isActive = $isPreview ? ($page === $key || ($key === 'curriculum' && $page === 'editor') || ($key === 'student-progress' && $page === 'student-detail')) : request()->routeIs($routeName, str_replace('.index', '.*', $routeName)))
                    <a class="{{ $isActive ? 'active' : '' }}" @if($isActive) aria-current="page" @endif href="{{ route($routeName) }}"><x-icon :name="$icon" /><span>{{ $label }}</span>@if($key === 'students' && $isPreview)<small>١٢٨</small>@endif</a>
                @endif
            @endforeach
        </nav>
        @if(auth('web')->user()->can('students.manage') || auth('web')->user()->can('audit.view'))<nav class="instructor-nav" aria-label="التنبيهات"><a href="{{ route('admin.notifications.index') }}" class="{{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}"><x-icon name="bell"/><span>التنبيهات والتجديد</span></a></nav>@endif
        @if(auth('web')->user()->is_super_admin)
            <nav class="instructor-nav" aria-label="إعدادات الحساب"><a href="{{ route('admin.profile.edit') }}" class="{{ request()->routeIs('admin.profile.*') ? 'active' : '' }}" @if(request()->routeIs('admin.profile.*')) aria-current="page" @endif><x-icon name="user"/><span>بيانات حسابي</span></a></nav>
        @endif
        <div class="instructor-sidebar-bottom"><div class="instructor-tip"><x-icon name="book" /><b>التعلّم يبدأ بمحتوى رائع</b><p>رتّب دروسك، وتابع أثرها في رحلة طلابك.</p></div><a href="{{ route('student.login') }}"><x-icon name="arrow-left" /> دخول الطالب</a></div>
    </aside>
    <div class="instructor-workspace">
        <header class="instructor-topbar">
            <button class="icon-btn admin-menu-button" data-admin-nav-toggle aria-label="فتح قائمة الأستاذ" aria-controls="instructor-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
            <div class="instructor-breadcrumb">مساحة الأستاذ <span>/</span> <b>{{ $title ?? 'نظرة عامة' }}</b></div>
            <div class="instructor-topbar-actions"><button class="icon-btn" data-theme-toggle aria-label="تبديل الوضع الليلي"><x-icon name="moon" /></button>
                @unless($isPreview)
                    @if(auth('web')->user()->can('students.manage') || auth('web')->user()->can('audit.view'))<a class="icon-btn audit-alert-link" href="{{ route('admin.notifications.index') }}" data-notifications-url="{{ route('admin.notifications.unread-count') }}" aria-label="تنبيهاتي"><x-icon name="bell"/><span data-notification-count hidden>0</span></a>@endif
                @endunless
                <span class="admin-topbar-divider"></span><span class="admin-avatar">{{ mb_substr($adminName, 0, 1) }}</span><div class="instructor-profile"><b>{{ $adminName }}</b><small>أستاذ طب الأسنان</small></div>
                @unless($isPreview)<form method="post" action="{{ route('admin.logout') }}">@csrf<button class="icon-btn" title="تسجيل الخروج" aria-label="تسجيل الخروج"><x-icon name="logout" /></button></form>@endunless
            </div>
        </header>
        <main class="admin-page">
            <div class="notification-arrival" data-notification-arrival role="status" aria-live="polite" hidden><x-icon name="bell"/><span data-notification-message></span><a class="text-link" href="{{ route('admin.notifications.index') }}">عرض التنبيهات</a></div>
            @if(session('status'))<div class="status-msg" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if(!request()->routeIs('admin.lessons.edit','admin.courses.lessons.create','admin.students.create','admin.students.edit','admin.profile.*','admin.notifications.*'))<x-step-tip>{{ match(true) { request()->routeIs('admin.grade-levels.*') => 'ابدأ باسم المرحلة الدراسية؛ ستظهر عند إضافة المواد وحسابات الطلاب.', request()->routeIs('admin.courses.*') => 'اختر المرحلة، اكتب اسم المادة، ثم انشرها أو احفظها كمسودة. أضف المحاضرات من صفحة المحاضرات.', request()->routeIs('admin.students.*') => 'افتح ملف الطالب لإدارة اشتراكه ومواده ورمز دخوله، مع الاحتفاظ بتقدّمه.', request()->routeIs('admin.student-progress*') => 'اختر الطالب أو المادة لتعرف آخر محاضرة ونقطة توقفه.', request()->routeIs('admin.admins.*','admin.roles.*') => 'امنح كل عضو صلاحيات المهام التي يحتاجها فقط.', default => 'اختر القسم من القائمة؛ التغييرات التي تحفظها تظهر مباشرة في المنصة.' } }}</x-step-tip>@endif
            @yield('content')
        </main>
        <footer class="instructor-footer"><span>{{ $siteBrand['name'] }} · {{ $siteBrand['tagline'] }}</span><span>{{ $isPreview ? 'بيانات تجريبية · تحفظ تغييرات المعاينة على جهازك' : 'مساحة إدارة المحتوى والطلاب' }}</span></footer>
    </div>
    <div class="admin-toast" data-admin-toast role="status" hidden></div>
    <dialog class="admin-dialog" data-admin-dialog><form method="dialog" class="admin-dialog-close"><button class="icon-btn" aria-label="إغلاق"><x-icon name="close" /></button></form><div data-admin-dialog-content></div></dialog>
</body>
</html>
