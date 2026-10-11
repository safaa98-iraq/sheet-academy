@php
    $subscriptionMode = old('subscription_duration', isset($student) && !$student->subscription_expires_at ? 'unlimited' : 'custom');
    $subscriptionDate = old('subscription_expires_at', isset($student) ? $student->subscription_expires_at?->copy()->timezone('Asia/Baghdad')->toDateString() : '');
@endphp
<fieldset class="student-access" data-subscription-fields><legend>مدة الاشتراك</legend>
<x-step-tip>عند اختيار تاريخ جديد من التقويم ينتهي الاشتراك بنهاية اليوم المحدد (23:59 بتوقيت بغداد). بعدها يتوقف الدخول والمشاهدة تلقائياً، ويبقى التقدّم محفوظاً.</x-step-tip>
<div class="profile-fields-grid">
    <label for="subscription-duration">طريقة تحديد الاشتراك<select id="subscription-duration" name="subscription_duration" data-subscription-duration><option value="custom" @selected($subscriptionMode !== 'unlimited')>تاريخ انتهاء أختاره بنفسي</option><option value="unlimited" @selected($subscriptionMode === 'unlimited')>بدون تاريخ انتهاء</option></select></label>
    <label for="subscription-date" data-subscription-date @if($subscriptionMode === 'unlimited') hidden @endif>تاريخ انتهاء الاشتراك<input id="subscription-date" type="date" dir="ltr" name="subscription_expires_at" value="{{ $subscriptionDate }}" @if($subscriptionMode !== 'unlimited') required @else disabled @endif aria-describedby="subscription-date-help"></label>
</div>
<p class="muted" id="subscription-date-help">لتجديد الاشتراك اختر تاريخاً لاحقاً ثم احفظ التغييرات. لإزالة تاريخ الانتهاء اختر «بدون تاريخ انتهاء».</p>
@if(isset($student) && $student->subscription_expires_at)
    <p class="subscription-current"><x-icon name="clock"/><span>الانتهاء الحالي: <b dir="ltr">{{ $student->subscription_expires_at->copy()->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}</b> بتوقيت بغداد — {{ $student->subscriptionIsActive() ? 'الاشتراك سارٍ' : 'الاشتراك منتهٍ' }}</span></p>
    @unless($student->subscriptionIsActive())<p class="muted">يمكنك حفظ بيانات الطالب دون تغيير هذا التاريخ؛ يبقى اشتراكه منتهياً حتى تجدّده.</p>@endunless
@endif
</fieldset>
