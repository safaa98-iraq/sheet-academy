@extends('student.layout', ['title' => 'حسابي وبياناتي', 'page' => 'account'])
@section('content')
<main class="content-wrap account-page">
<section class="account-hero" aria-labelledby="account-title">
<div class="account-identity"><span class="account-avatar" aria-hidden="true">{{ mb_substr($student->name, 0, 1) }}</span><div><span class="eyebrow">مساحتك الشخصية</span><h1 id="account-title">{{ $student->name }}</h1><p class="muted">بياناتك، اشتراكك، ورحلتك التعليمية في مكان واحد.</p></div></div>
<span class="account-status"><span class="status-dot"></span>حساب نشط</span>
</section>
<div class="account-stats" aria-label="ملخص التعلّم">
<article><x-icon name="book"/><span>المواد المتاحة</span><strong>{{ $accountTotals['courses'] }}</strong></article>
<article><x-icon name="check"/><span>المحاضرات المكتملة</span><strong>{{ $accountTotals['completed'] }}</strong></article>
<article><x-icon name="clock"/><span>دقائق التعلّم الفعلية</span><strong>{{ $accountTotals['minutes'] }}</strong></article>
</div>
<div class="account-columns">
<section class="account-card" aria-labelledby="personal-data-title"><div class="account-card-heading"><x-icon name="user"/><h2 id="personal-data-title">بياناتي الشخصية</h2></div>
<dl class="account-details">
<div><dt>الاسم الكامل</dt><dd>{{ $student->name }}</dd></div>
<div><dt>الكلية</dt><dd>{{ $student->college ?: 'غير مسجلة' }}</dd></div>
<div><dt>المرحلة الدراسية</dt><dd>{{ $student->studyGradeLevel?->name ?? $student->gradeLevel?->name ?? 'غير مسجلة' }}</dd></div>
<div><dt>رقم الهاتف</dt><dd dir="ltr">{{ $student->phone ?: 'غير مسجل' }}</dd></div>
<div><dt>البريد الإلكتروني</dt><dd dir="ltr">{{ $student->email ?: 'غير مسجل' }}</dd></div>
<div><dt>تاريخ الانضمام</dt><dd>{{ $student->created_at->copy()->timezone('Asia/Baghdad')->format('Y-m-d') }}</dd></div>
</dl><x-step-tip>هذه بياناتك المسجلة لدى الأستاذ. لتصحيحها أو تحديث رقم الهاتف، تواصل معه.</x-step-tip>
</section>
<div class="account-stack">
<section class="account-card account-subscription" aria-labelledby="subscription-title"><div class="account-card-heading"><x-icon name="shield"/><h2 id="subscription-title">اشتراكي وصلاحية الدخول</h2></div>
<p class="account-expiry">{{ $student->subscription_expires_at?->copy()->timezone('Asia/Baghdad')->format('Y-m-d') ?? 'بدون تاريخ انتهاء' }}</p><p class="muted">{{ $student->subscription_expires_at ? 'تاريخ انتهاء الاشتراك بتوقيت بغداد' : 'اشتراكك غير محدد بتاريخ انتهاء.' }}</p>
<dl class="account-details compact"><div><dt>حالة الاشتراك</dt><dd>متاح</dd></div><div><dt>صلاحية رمز الدخول</dt><dd>{{ $currentToken?->expires_at?->copy()->timezone('Asia/Baghdad')->format('Y-m-d H:i') ?? 'بدون انتهاء' }}</dd></div><div><dt>الأجهزة المتزامنة المسموحة</dt><dd>{{ $currentToken?->device_limit ?? 1 }}</dd></div></dl>
<x-step-tip>تواصل مع الأستاذ لتجديد الاشتراك قبل انتهائه. تنتهي إمكانية الدخول عند انتهاء الاشتراك أو رمز الدخول، أيهما يأتي أولاً.</x-step-tip>
</section>
<section class="account-card" aria-labelledby="preferences-title"><div class="account-card-heading"><x-icon name="play"/><h2 id="preferences-title">تفضيلات المشاهدة</h2></div><dl class="account-details compact"><div><dt>الدقة المحفوظة</dt><dd>{{ !$student->preference || $student->preference->video_quality === 'auto' ? 'تلقائي' : $student->preference->video_quality }}</dd></div><div><dt>سرعة التشغيل</dt><dd>{{ $student->preference?->playback_speed ?? 1 }}×</dd></div></dl><p class="muted">غيّر الدقة والسرعة من المشغّل؛ تُحفظ لحسابك تلقائياً.</p></section>
</div>
</div>
@if($resumeLesson)<section class="account-resume"><div><span class="eyebrow">تابع من حيث توقفت</span><h2>{{ $resumeLesson['title'] }}</h2><p class="muted">آخر نقطة محفوظة: {{ sprintf('%02d:%02d', intdiv((int) $resumeLesson['position'], 60), (int) $resumeLesson['position'] % 60) }}</p></div><a class="btn primary" href="{{ $resumeLesson['url'] }}" data-open-view><x-icon name="play"/>متابعة المحاضرة</a></section>@endif
<section class="account-card" aria-labelledby="my-courses-title"><div class="account-card-heading"><x-icon name="book"/><h2 id="my-courses-title">موادي وتقدّمي</h2><a class="text-link" href="{{ route('student.progress') }}">تفاصيل التقدّم</a></div><div class="account-course-grid">@forelse($accountCourses as $course)<article class="account-course"><a href="{{ $course['url'] }}"><h3>{{ $course['title'] }}</h3></a><x-progress-bar :value="$course['percentage']" :label="'التقدّم في '.$course['title']"/><div class="account-course-meta"><strong>{{ $course['percentage'] }}٪</strong><span>{{ $course['completed'] }} من {{ $course['total'] }} محاضرات مكتملة</span></div></article>@empty<div class="account-empty"><x-icon name="book"/><h3>رحلتك تبدأ بمادتك الأولى</h3><p class="muted">تظهر المواد هنا عند تخصيصها لك ونشرها من الأستاذ.</p></div>@endforelse</div></section>
<section class="account-card"><div class="account-card-heading"><x-icon name="shield"/><h2>جلسة الدخول الحالية</h2></div><dl class="account-details compact"><div><dt>الجهاز</dt><dd>{{ $deviceLabel }}</dd></div><div><dt>آخر نشاط</dt><dd>{{ $currentDevice?->last_seen_at?->copy()->timezone('Asia/Baghdad')->format('Y-m-d H:i') ?? 'الآن' }}</dd></div></dl><p class="muted">الدخول من جهاز آخر ينهي الجلسة الحالية للمحافظة على حسابك.</p></section>
@if($siteBrand['contact_phone'] || $siteBrand['contact_email'])<section class="account-card"><div class="account-card-heading"><x-icon name="bell"/><h2>التواصل مع الأستاذ</h2></div><p class="muted">للتجديد أو تحديث بياناتك، تواصل عبر:</p><dl class="account-details compact">@if($siteBrand['contact_phone'])<div><dt>الهاتف</dt><dd dir="ltr"><a href="tel:{{ $siteBrand['contact_phone'] }}">{{ $siteBrand['contact_phone'] }}</a></dd></div>@endif @if($siteBrand['contact_email'])<div><dt>البريد الإلكتروني</dt><dd dir="ltr"><a href="mailto:{{ $siteBrand['contact_email'] }}">{{ $siteBrand['contact_email'] }}</a></dd></div>@endif</dl></section>@endif
</main>
@endsection
