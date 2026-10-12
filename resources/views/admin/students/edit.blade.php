@extends('admin.layout', ['title' => 'تعديل الطالب'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">إدارة الحساب</span><h1>تعديل بيانات الطالب</h1><p class="muted">{{ $student->name }} — عدّل البيانات والاشتراك والمواد، ثم احفظ مرة واحدة.</p></div><a href="{{ route('admin.students.show', $student) }}" class="btn"><x-icon name="arrow-right"/>ملف الطالب</a></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.students.update',$student) }}">@csrf @method('PUT')
<section class="student-edit-section" aria-labelledby="student-details-title">
<div class="publisher-step-title"><span aria-hidden="true">١</span><div><h2 id="student-details-title">بيانات الطالب</h2><p class="muted">بيانات التواصل والمرحلة الدراسية.</p></div></div>
@include('admin.students.profile-fields')
</section>
<section id="subscription" class="student-edit-section" aria-labelledby="student-subscription-title">
<div class="publisher-step-title"><span aria-hidden="true">٢</span><div><h2 id="student-subscription-title">تاريخ انتهاء الاشتراك</h2><p class="muted">تاريخ مرن تختاره من التقويم.</p></div></div>
@include('admin.students.subscription-fields')
</section>
<section class="student-edit-section" aria-labelledby="student-content-title">
<div class="publisher-step-title"><span aria-hidden="true">٣</span><div><h2 id="student-content-title">المواد المتاحة</h2><p class="muted">حدد المحتوى الذي يستطيع الطالب مشاهدته.</p></div></div>
<x-step-tip>اختر مواد صف الطالب تلقائياً، أو خصّص له مواد بالاسم. تنبيهات المحاضرات تلتزم بنطاق وصوله نفسه.</x-step-tip>@include('admin.students.access-fields')
</section>
<div class="student-save-bar"><p class="muted">زر واحد يحفظ البيانات وتاريخ الاشتراك والمواد. رمز الدخول وتقدّم المشاهدة يبقيان محفوظين.</p><button class="btn primary" type="submit"><x-icon name="save"/>حفظ جميع التغييرات</button></div>
</form>
<details class="student-account-advanced"><summary><x-icon name="shield"/>إجراءات الحساب ورمز الدخول</summary><x-step-tip>افتح هذا القسم فقط لتجميد الحساب أو تفعيله أو إصدار رمز بديل. هذه إجراءات مستقلة عن حفظ البيانات.</x-step-tip>
@include('admin.students.actions', ['student' => $student])
</details>
@endsection
