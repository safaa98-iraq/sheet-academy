@can('update', $student)
<section class="admin-form">
    <h2>التحكم بحساب الطالب</h2>
    <div class="action-row student-account-actions">
        <a class="btn" href="{{ route('admin.students.edit', $student) }}"><x-icon name="edit"/><span>تعديل البيانات والمواد</span></a>
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
    <h3>رمز الدخول</h3>
    @if($student->tokens->isNotEmpty())
        <button type="button" class="btn" data-reveal-student-token="{{ route('admin.students.token.reveal', $student) }}" data-student-name="{{ $student->name }}"><x-icon name="eye"/><span>عرض التوكن ونسخه</span></button>
    @endif
    <p class="muted">التوكن هو مفتاح دخول الطالب. جرّب عرضه ونسخه أولاً؛ إصدار رمز جديد يُبطل الرمز السابق.</p>
    <details class="student-token-options"><summary><x-icon name="key"/> إصدار توكن جديد وتحديد صلاحيته</summary>
    @if($student->status === 'active')
        <p class="muted">الرمز الجديد يلغي السابق ويمكن عرضه ونسخه من ملف الطالب.</p>
        <form method="post" action="{{ route('admin.students.token', $student) }}" data-confirm="سيُلغى رمز الدخول السابق. هل تريد إصدار رمز جديد؟">
            @csrf
            <label>انتهاء صلاحية التوكن (اختياري)<input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></label>
            <label>عدد الأجهزة المتزامنة<select name="device_limit">@foreach(range(1, 10) as $limit)<option value="{{ $limit }}" @selected((int) old('device_limit', $student->tokens->first()?->device_limit ?? config('audit.device_limit', 1)) === $limit)>{{ $limit }}</option>@endforeach</select></label>
            <button class="btn primary" style="margin-top:16px"><x-icon name="key"/><span>إصدار توكن جديد</span></button>
        </form>
    @else
        <p class="muted">أعد تفعيل الحساب أولاً لإصدار توكن جديد. يبقى الدخول ممنوعاً أثناء التجميد أو الإيقاف.</p>
    @endif
    </details>
</section>
@include('admin.students.token-dialog')
@endcan
