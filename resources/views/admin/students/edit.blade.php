@extends('admin.layout')
@section('content')
<div class="admin-head"><div><span class="eyebrow">إدارة الحساب</span><h1>تعديل {{ $student->name }}</h1></div><a href="{{ route('admin.students.index') }}" class="btn"><x-icon name="arrow-right"/><span>رجوع</span></a></div><form class="admin-form" method="post" action="{{ route('admin.students.update',$student) }}">@csrf @method('PUT')<label>اسم الطالب</label><input name="name" required value="{{ old('name',$student->name) }}"><label>البريد الإلكتروني</label><input name="email" type="email" value="{{ old('email',$student->email) }}">@include('admin.students.access-fields')<button class="btn primary" style="margin-top:18px"><x-icon name="save"/><span>حفظ التغييرات</span></button></form>
@include('admin.students.actions', ['student' => $student])
@endsection
