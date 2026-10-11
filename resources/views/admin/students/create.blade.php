@extends('admin.layout', ['title' => 'إنشاء طالب'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">حساب جديد</span><h1>إضافة طالب</h1><p class="muted">بياناته، اشتراكه، ومواده في خطوة واحدة.</p></div><a href="{{ route('admin.students.index') }}" class="btn"><x-icon name="arrow-right"/>رجوع</a></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.students.store') }}">@csrf
<h2>١. بيانات الطالب</h2>@include('admin.students.profile-fields')
<h2>٢. المواد المتاحة</h2><x-step-tip>المرحلة الدراسية أعلاه تصف الطالب. هنا اختر المواد التي اشترك بها، أو امنحه مرحلة كاملة.</x-step-tip>@include('admin.students.access-fields')
<h2>٣. الاشتراك والدخول</h2>@include('admin.students.subscription-fields')
<input type="hidden" name="device_limit" value="1">
<x-step-tip>بعد الحفظ يظهر رمز من 8 خانات مرة واحدة. انسخه وأرسله للطالب؛ لا يمكن استرجاعه لاحقاً، ويمكن إصدار بديل عند فقده.</x-step-tip>
<button class="btn primary" type="submit"><x-icon name="plus"/>إنشاء الطالب وإصدار الرمز</button>
</form>
@endsection
