@extends('admin.layout')
@section('content')
<div class="admin-head"><div><span class="eyebrow">المراحل والمواد والدروس</span><h1>{{ $gradeLevel->exists ? 'تعديل المرحلة الصفية' : 'إضافة مرحلة صفية' }}</h1></div><a class="btn" href="{{ route('admin.grade-levels.index') }}">رجوع</a></div>
<form class="admin-form" method="post" action="{{ $gradeLevel->exists ? route('admin.grade-levels.update', $gradeLevel) : route('admin.grade-levels.store') }}">
@csrf @if($gradeLevel->exists) @method('PUT') @endif
<label>اسم المرحلة الصفية<input name="name" required maxlength="100" placeholder="مثلاً: الصف الأول" value="{{ old('name', $gradeLevel->name) }}"></label>
<label>الترتيب<input type="number" name="position" required min="0" max="1000" value="{{ old('position', $gradeLevel->position) }}"></label>
<button class="btn primary" style="margin-top:18px">حفظ المرحلة</button>
</form>
@endsection
