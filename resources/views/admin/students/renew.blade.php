@extends('admin.layout', ['title' => 'تجديد الاشتراك والتوكن'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">إدارة الوصول</span><h1>تجديد الاشتراك والتوكن</h1><p class="muted">{{ $student->name }} — تاريخ واحد للاشتراك وصلاحية الرمز، وحفظ واحد لكل الخيارات.</p></div><a class="btn" href="{{ route('admin.students.show', $student) }}">ملف الطالب</a></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.students.renew.update', $student) }}" data-confirm="حفظ خيارات التجديد؟ إذا اخترت رمزاً بديلاً، يُلغى السابق وتنتهي جلساته مع الاحتفاظ بالتقدّم.">@csrf @method('PUT')
<section class="student-edit-section"><h2>١. تاريخ انتهاء الوصول</h2><x-step-tip>الاشتراك والتوكن ينتهيان في التاريخ نفسه، عند نهاية اليوم بتوقيت بغداد. لا تتغيّر المواد المشترك بها أثناء التجديد.</x-step-tip>
@php($defaultDate = $student->subscription_expires_at?->isFuture() ? $student->subscription_expires_at->copy()->timezone('Asia/Baghdad')->toDateString() : now()->addDays(30)->timezone('Asia/Baghdad')->toDateString())
<div data-subscription-fields><label>مدة الاشتراك<select name="subscription_duration" data-subscription-duration><option value="custom" @selected(old('subscription_duration', $student->subscription_expires_at ? 'custom' : 'unlimited') === 'custom')>تاريخ أختاره من التقويم</option><option value="unlimited" @selected(old('subscription_duration', $student->subscription_expires_at ? 'custom' : 'unlimited') === 'unlimited')>بدون تاريخ انتهاء</option></select></label><label data-subscription-date>آخر يوم للاشتراك<input name="subscription_expires_at" type="date" min="{{ now()->timezone('Asia/Baghdad')->toDateString() }}" value="{{ old('subscription_expires_at', $defaultDate) }}"></label></div></section>
<section class="student-edit-section"><h2>٢. ماذا نفعل برمز الدخول؟</h2>
<label><input type="radio" name="mode" value="extend" @checked(old('mode', $replaceRequested || !$visibleToken ? 'replace' : 'extend') === 'extend') @disabled(!$currentToken)> تمديد الرمز الحالي — لا يتغير الرمز ولا تنتهي الجلسة الحالية</label>
<label><input type="radio" name="mode" value="replace" @checked(old('mode', $replaceRequested || !$visibleToken ? 'replace' : 'extend') === 'replace')> إصدار رمز بديل — إلغاء السابق وجلساته، مع الاحتفاظ بالتقدّم</label>
@if($visibleToken)<p class="muted">الرمز الحالي: <code dir="ltr">{{ $visibleToken }}</code></p>@elseif($currentToken)<x-step-tip>الرمز القديم محفوظ كتجزئة فقط؛ لا يمكن كشفه. استبداله مرة واحدة يجعل الرمز الجديد قابلاً للنسخ لاحقاً من جدول الطلاب.</x-step-tip>@endif
</section>
@if($student->status !== 'active')<section class="student-edit-section"><h2>٣. حالة الحساب</h2><label><input type="checkbox" name="reactivate" value="1" @checked(old('reactivate'))> إعادة تفعيل الحساب بعد التجديد</label><x-step-tip>الحساب مجمّد أو موقوف. التجديد وحده لا يرفع التجميد؛ فعّل الخيار فقط إذا أردت السماح بالدخول.</x-step-tip></section>@endif
<div class="student-save-bar"><span class="muted">البيانات والمواد ونقطة المشاهدة محفوظة.</span><button class="btn primary"><x-icon name="save"/>حفظ التجديد</button></div>
</form>
@endsection
