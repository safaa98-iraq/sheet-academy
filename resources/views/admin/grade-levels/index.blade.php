@extends('admin.layout')
@section('content')
<div class="admin-head"><div><span class="eyebrow">تنظيم المحتوى التعليمي</span><h1>المراحل الصفية</h1><p class="muted">أنشئ المرحلة أولاً، ثم أضف موادها ودروسها.</p></div>@can('courses.manage')<a class="btn primary" href="{{ route('admin.grade-levels.create') }}">＋ إضافة مرحلة صفية</a>@endcan</div>
<div class="table-wrap"><table><thead><tr><th>الترتيب</th><th>المرحلة الصفية</th><th>المواد</th><th>الإجراءات</th></tr></thead><tbody>
@forelse($gradeLevels as $gradeLevel)
<tr><td>{{ $gradeLevel->position }}</td><td><b>{{ $gradeLevel->name }}</b></td><td>{{ $gradeLevel->courses_count }}</td><td><div class="action-row">
<a class="btn small" href="{{ route('admin.courses.index', ['grade_level_id' => $gradeLevel->id]) }}">عرض المواد</a>
@can('courses.manage')
<a class="btn small" href="{{ route('admin.courses.create', ['grade_level_id' => $gradeLevel->id]) }}">إضافة مادة</a>
<a class="btn small" href="{{ route('admin.grade-levels.edit', $gradeLevel) }}">تعديل</a>
<form method="post" action="{{ route('admin.grade-levels.destroy', $gradeLevel) }}" data-confirm="هل تريد حذف هذه المرحلة الصفية؟">@csrf @method('DELETE')<button class="btn small">حذف</button></form>
@endcan
</div></td></tr>
@empty<tr><td colspan="4">لا توجد مراحل صفية بعد. أضف الصف الأول أو الصف الثاني للبدء.</td></tr>@endforelse
</tbody></table></div>
@endsection
