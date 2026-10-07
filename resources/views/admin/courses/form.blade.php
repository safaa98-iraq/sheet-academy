@extends('admin.layout')
@section('content')
<div class="admin-head"><div><span class="eyebrow">المواد وهيكلتها</span><h1>{{ $course->exists?'تعديل المادة':'إنشاء مادة' }}</h1></div><a class="btn" href="{{ route('admin.courses.index') }}">رجوع</a></div>
<form class="admin-form" method="post" action="{{ $course->exists?route('admin.courses.update',$course):route('admin.courses.store') }}">
    @csrf @if($course->exists)@method('PUT')@endif
    <label>المرحلة الصفية<select name="grade_level_id" required><option value="">اختر المرحلة الصفية</option>@foreach($gradeLevels as $gradeLevel)<option value="{{ $gradeLevel->id }}" @selected((string) old('grade_level_id', $course->grade_level_id) === (string) $gradeLevel->id)>{{ $gradeLevel->name }}</option>@endforeach</select></label>
    <a class="text-link" href="{{ route('admin.grade-levels.create') }}">إضافة مرحلة صفية</a>
    <label>عنوان المادة<input name="title" value="{{ old('title',$course->title) }}" required maxlength="180"></label>
    <label>الوصف<textarea name="description" rows="5">{{ old('description',$course->description) }}</textarea></label>
    <label>حالة النشر<select name="publication_status" required><option value="draft" @selected(old('publication_status',$course->publication_status??($course->published_at?'published':'draft'))==='draft')>مسودة</option><option value="published" @selected(old('publication_status',$course->publication_status??($course->published_at?'published':'draft'))==='published')>منشور</option><option value="scheduled" @selected(old('publication_status',$course->publication_status??'draft')==='scheduled')>مجدول</option></select></label>
    <label>موعد النشر المجدول<input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at',$course->scheduled_at?->format('Y-m-d\TH:i')) }}"></label>
    <button class="btn primary" style="margin-top:18px">حفظ المادة</button>
</form>
@if($course->exists)
    <div class="admin-head"><div><h2>منهج المادة</h2><p class="muted">إدارة الأجزاء والفصول والأقسام والدروس من محرر واحد.</p></div><a class="btn primary" href="{{ route('admin.courses.curriculum',$course) }}">فتح محرر المنهج</a></div>
    <section class="admin-form"><h2>تسجيل الطلاب</h2><p class="muted">هذه القائمة لطلاب الوصول إلى مواد محددة. طلاب المرحلة الكاملة يصلون تلقائياً إلى مواد مرحلتهم ويُعدّل نطاق وصولهم من ملف الطالب. حفظ الاختيار يستبدل التسجيلات المباشرة.</p>
        <form method="post" action="{{ route('admin.courses.students.sync',$course) }}">@csrf @method('PUT')
            <label>الطلاب<select name="student_ids[]" multiple size="8">@foreach($students as $student)<option value="{{ $student->id }}" @selected($course->students->contains('id',$student->id))>{{ $student->name }}{{ $student->email?' · '.$student->email:'' }}</option>@endforeach</select></label>
            <button class="btn primary" style="margin-top:12px">حفظ تسجيلات الطلاب</button>
        </form>
    </section>
    <form method="post" action="{{ route('admin.courses.destroy',$course) }}" data-confirm="سيُخفى هذا المحتوى عن الطلاب. هل تريد المتابعة؟">@csrf @method('DELETE')<button class="btn">حذف المادة حذفاً ناعماً</button></form>
@endif
@endsection
