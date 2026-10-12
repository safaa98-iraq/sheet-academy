@extends('admin.layout', ['title' => 'بيانات حسابي'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">الأدمن الرئيسي</span><h1>بيانات حسابي</h1><p class="muted">عدّل اسمك وبريد الدخول، أو اختر كلمة مرور جديدة.</p></div></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.profile.update') }}">
    @csrf @method('PUT')
    <h2>الاسم والبريد الإلكتروني</h2>
    <x-step-tip>البريد الذي تحفظه هنا هو بريد الدخول القادم. تبقى صلاحياتك كأدمن رئيسي محفوظة.</x-step-tip>
    <div class="profile-fields-grid">
        <label for="profile-name">الاسم الكامل<input id="profile-name" name="name" value="{{ old('name', $admin->name) }}" required maxlength="150" autocomplete="name"></label>
        <label for="profile-email">بريد الدخول<input id="profile-email" name="email" type="email" dir="ltr" value="{{ old('email', $admin->email) }}" required maxlength="190" autocomplete="username"></label>
    </div>
    <h2>كلمة المرور <span class="muted small">(تغييرها اختياري)</span></h2>
    <x-step-tip>اترك الحقلين التاليين فارغين للاحتفاظ بكلمة المرور. عند تغييرها استخدم 12 حرفاً على الأقل.</x-step-tip>
    <div class="profile-fields-grid">
        <label for="new-password">كلمة المرور الجديدة<input id="new-password" name="password" type="password" dir="ltr" minlength="12" autocomplete="new-password"></label>
        <label for="new-password-confirmation">تأكيد كلمة المرور الجديدة<input id="new-password-confirmation" name="password_confirmation" type="password" dir="ltr" minlength="12" autocomplete="new-password"></label>
    </div>
    <h2>تأكيد الهوية والحفظ</h2>
    <x-step-tip>أدخل كلمة المرور الحالية لحماية حسابك، ثم احفظ كل التغييرات بزر واحد.</x-step-tip>
    <label for="current-password">كلمة المرور الحالية<input id="current-password" name="current_password" type="password" dir="ltr" required autocomplete="current-password"></label>
    <button class="btn primary" type="submit"><x-icon name="save"/>حفظ بياناتي</button>
</form>
@endsection
