@extends('admin.layout', ['title' => 'إنشاء طالب'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">حساب جديد</span><h1>إضافة طالب</h1><p class="muted">بياناته، اشتراكه، ومواده في خطوة واحدة.</p></div><a href="{{ route('admin.students.index') }}" class="btn"><x-icon name="arrow-right"/>رجوع</a></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.students.store') }}">@csrf
<h2>١. بيانات الطالب</h2>@include('admin.students.profile-fields')
<h2>٢. المواد المتاحة</h2><x-step-tip>الافتراضي هو مواد صف الطالب تلقائياً. اختر «مواد محددة» إذا أردت تخصيص مواد بالاسم بدلاً من الصف.</x-step-tip>@include('admin.students.access-fields')
<h2>٣. الاشتراك والدخول</h2>@include('admin.students.subscription-fields')
<input type="hidden" name="device_limit" value="1">
<x-step-tip>بعد الحفظ يصدر رمز من 8 خانات. يمكنك نسخه لاحقاً من جدول الطلاب أو ملف الطالب وإرساله إليه.</x-step-tip>
<button class="btn primary" type="submit"><x-icon name="plus"/>إنشاء الطالب وإصدار الرمز</button>
</form>
@endsection
