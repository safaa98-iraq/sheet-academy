@extends('admin.layout', ['title' => 'تعديل الطالب'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">إدارة الحساب</span><h1>تعديل {{ $student->name }}</h1></div><a href="{{ route('admin.students.index') }}" class="btn"><x-icon name="arrow-right"/>رجوع</a></div>
<form class="admin-form student-profile-form" method="post" action="{{ route('admin.students.update',$student) }}">@csrf @method('PUT')
<h2>بيانات الطالب</h2>@include('admin.students.profile-fields')
<h2>المواد المتاحة</h2><x-step-tip>اختر المواد المشترك بها؛ يتلقى الطالب تنبيهات المحاضرات الجديدة فيها فقط.</x-step-tip>@include('admin.students.access-fields')
@include('admin.students.subscription-fields')
<button class="btn primary"><x-icon name="save"/>حفظ التغييرات</button>
</form>
@include('admin.students.actions', ['student' => $student])
@endsection
