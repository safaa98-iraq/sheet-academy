<!doctype html>
<html lang="ar" dir="rtl">
<head>@include('components.head', ['title' => 'دخول الطالب'])</head>
<body class="auth-page" data-storage-scope="guest">
<main class="login">
    <x-login-hero/>
    <section class="login-form" aria-labelledby="login-title">
        <button class="icon-btn login-theme" data-theme aria-label="تبديل الوضع الليلي"><x-icon name="moon"/></button>
        <form class="form-box" method="post" action="{{ route('student.login.store') }}">
            @csrf
            <div class="login-icon"><x-icon name="key"/></div>
            <span class="eyebrow">أهلاً بعودتك</span>
            <h2 id="login-title">جاهز لخطوتك التالية؟</h2>
            <p class="muted">أدخل رمز الوصول وافتح مساحتك التعليمية.</p>
            <label for="token">رمز الوصول</label>
            <div class="token-input"><x-icon name="shield"/><input id="token" name="token" value="{{ old('token') }}" required autocomplete="off" spellcheck="false" dir="ltr" placeholder="أدخل رمز الوصول الخاص بك" aria-describedby="token-help @error('token') token-error @enderror" @error('token') aria-invalid="true" @enderror></div>
            <small id="token-help" class="muted">استخدم الرمز الذي وصل إليك من إدارة المنصة.</small>
            @error('token')<p class="error-message" id="token-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn primary full login-submit" type="submit">الدخول إلى مساحتي<x-icon name="arrow-left"/></button>
            <p class="login-support">تحتاج إلى مساعدة؟ <button type="button" class="text-button" data-modal="token-help-modal">طريقة الحصول على الرمز</button></p>
            <div class="login-divider">مساحة مخصصة لطلاب المنصة</div>
            <a class="instructor-login" href="{{ route('admin.login') }}"><x-icon name="user"/>دخول الأستاذ والإدارة<x-icon name="arrow-left"/></a>
        </form>
        <small class="login-copyright">© {{ date('Y') }} عيادة التعلّم. تعلّم بثقة.</small>
    </section>
</main>
<x-modal id="token-help-modal" title="كيف أحصل على رمز الوصول؟"><p class="reading-copy">تمنحك إدارة المنصة رمزاً خاصاً بعد تسجيلك بالمادة. انسخ الرمز كما وصلك والصقه في الحقل. إذا انتهت صلاحيته، تواصل مع أستاذ المادة لإصدار رمز جديد.</p></x-modal>
<x-toast/>
</body>
</html>
