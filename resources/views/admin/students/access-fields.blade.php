<fieldset class="student-access">
<legend>المواد المشترك بها الطالب</legend><input type="hidden" name="access_type" value="courses">
<x-step-tip>حدد المواد التي اشترك بها هذا الطالب فقط. المرحلة الدراسية تصف دراسته، ولا تمنحه أي مادة تلقائياً. المواد غير المختارة تبقى محجوبة.</x-step-tip>
@if(isset($student) && $student->grade_level_id && $student->courses->isEmpty())<p class="error-message">هذا حساب قديم كان يصل بحسب المرحلة. اختر مواده بالاسم واحفظ لإتاحة المحتوى له.</p>@endif
<div class="checks">@forelse($courses as $course)<label><input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id, old('course_ids', isset($student) ? $student->courses->modelKeys() : [])))>{{ $course->title }} <small class="muted">{{ $course->gradeLevel?->name }}</small></label>@empty<p class="muted">أضف المواد أولاً ثم اختر اشتراكات الطالب.</p>@endforelse</div>
</fieldset>
