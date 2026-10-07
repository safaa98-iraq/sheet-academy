@extends('student.layout', ['title' => 'تعهد استخدام المحتوى'])

@section('content')
<main class="content-wrap student-agreement-page">
    <section class="student-agreement-card">
        <span class="eyebrow">قبل بدء التعلّم</span>
        <h1>تعهد استخدام المحتوى التعليمي</h1>
        <p>يهدف هذا التعهد إلى حماية حقوق إعداد المواد والحفاظ على خصوصية حسابك.</p>
        <blockquote>أتعهد باستخدام مواد المنصة لأغراضي التعليمية الشخصية فقط، وعدم تسجيلها أو نسخها أو نشرها أو مشاركة روابط الوصول مع الآخرين. أعلم أن الحساب مرتبط بهويتي، وأن محاولات العبث بالبصمة أو تجاوز ضوابط الوصول قد تُسجّل وتُراجع من إدارة المنصة، وقد تؤدي إلى إيقاف الحساب وفق سياسة المنصة.</blockquote>
        <p class="muted">قد تتضمن سجلات الحماية بيانات الجهاز وعنوان الشبكة ووقت الوصول لأغراض أمن الحساب والتحقيق في إساءة الاستخدام. هذه الضوابط لا تستطيع منع تصوير الشاشة بجهاز خارجي.</p>
        <form method="post" action="{{ route('student.agreement.accept') }}">
            @csrf
            <label class="agreement-check"><input type="checkbox" name="accepted" value="1" required> قرأت التعهد وأوافق على الالتزام به.</label>
            <button class="btn primary" type="submit">أوافق وأتابع إلى موادي</button>
        </form>
    </section>
</main>
@endsection
