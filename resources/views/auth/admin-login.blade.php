<!doctype html>
<html lang="ar" dir="rtl">
<head>@include('components.head', ['title' => 'دخول الأستاذ'])</head>
<body class="auth-page" data-storage-scope="guest">
<main class="login">
    <x-login-hero audience="admin"/>
    <section class="login-form" aria-labelledby="login-title">
        <button class="icon-btn login-theme" data-theme aria-label="تبديل الوضع الليلي"><x-icon name="moon"/></button>
        <form class="form-box" method="post" action="{{ route('admin.login.store') }}">
            @csrf
            <div class="login-icon"><x-icon name="layers"/></div>
            <span class="eyebrow">مساحة الأستاذ وفريق الإدارة</span>
            <h2 id="login-title">كل التفاصيل بين يديك</h2>
            <p class="muted">سجّل الدخول لإدارة المحتوى ومتابعة طلابك.</p>
            @if($errors->any())<p class="error-message" role="alert">بيانات الدخول غير صحيحة.</p>@endif
            <label for="email">البريد الإلكتروني</label>
            <input id="email" name="email" type="email" dir="ltr" value="{{ old('email') }}" autocomplete="username" placeholder="name@example.com" required>
            <label for="password">كلمة المرور</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="أدخل كلمة المرور" required>
            <button class="btn primary full login-submit" type="submit">دخول لوحة الأستاذ<x-icon name="arrow-left"/></button>
            <div class="login-divider">إدارة تجربة التعلّم</div>
            <a class="instructor-login" href="{{ route('student.login') }}">العودة إلى دخول الطلاب<x-icon name="arrow-left"/></a>
        </form>
        <small class="login-copyright">© {{ date('Y') }} عيادة التعلّم. مساحة تصنع فرقاً.</small>
    </section>
</main>
</body>
</html>
