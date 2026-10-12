@can('update', $student)
<section class="admin-form">
    <h2>التحكم بحساب الطالب</h2>
    <div class="action-row student-account-actions">
        @unless(request()->routeIs('admin.students.edit', 'admin.students.show'))<a class="btn" href="{{ route('admin.students.edit', $student) }}"><x-icon name="edit"/><span>تعديل البيانات والمواد</span></a>@endunless
        @if($student->status === 'active')
            <form method="post" action="{{ route('admin.students.status', $student) }}" data-confirm="تجميد الحساب يمنع الطالب من الدخول ومتابعة الدروس. متابعة؟">@csrf @method('PATCH')<input type="hidden" name="status" value="frozen"><button class="btn"><x-icon name="pause"/><span>تجميد الحساب</span></button></form>
        @else
            <form method="post" action="{{ route('admin.students.status', $student) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="active"><button class="btn primary"><x-icon name="play"/><span>إعادة تفعيل الحساب</span></button></form>
        @endif
        @if($student->status !== 'suspended')
            <form method="post" action="{{ route('admin.students.status', $student) }}" data-confirm="هل تريد إيقاف حساب الطالب؟">@csrf @method('PATCH')<input type="hidden" name="status" value="suspended"><button class="btn"><x-icon name="ban"/><span>إيقاف الحساب</span></button></form>
        @endif
    </div>
    <p class="muted">التجميد والإيقاف يمنعان الوصول مع الاحتفاظ بالبيانات والتقدّم. استخدم إعادة التفعيل للسماح بالدخول مجدداً.</p>
    <h3>رمز الدخول والتجديد</h3>
    @if($visibleToken ?? null)<div class="token-table-cell"><code id="profile-student-token" dir="ltr">{{ $visibleToken }}</code><button type="button" class="btn small" data-copy-target="#profile-student-token"><x-icon name="copy"/><span data-copy-label>نسخ التوكن</span></button></div>@else<button type="button" class="btn" data-reveal-student-token="{{ route('admin.students.token.reveal', $student) }}" data-student-name="{{ $student->name }}">عرض التوكن ونسخه</button>@endif
    <p class="muted">الرمز مشفّر في قاعدة البيانات، وتسجيل الدخول يتحقق من تجزئته. النسخ متاح للأستاذ والأدمن المخوّل فقط.</p>
    <a class="btn primary" href="{{ route('admin.students.renew.edit', $student) }}"><x-icon name="refresh"/>تجديد الاشتراك أو إصدار توكن جديد</a>
</section>
@include('admin.students.token-dialog')
@endcan
