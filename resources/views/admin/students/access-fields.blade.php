<fieldset class="student-access" data-student-access>
<legend>ما المحتوى المتاح للطالب؟</legend>
@php($accessMode = old('access_type', isset($student) ? ($student->grade_level_id ? 'grade' : 'courses') : 'grade'))
<label><input type="radio" name="access_type" value="grade" @checked($accessMode === 'grade')> مواد صف الطالب تلقائياً</label>
<label><input type="radio" name="access_type" value="courses" @checked($accessMode === 'courses')> مواد يحددها الأستاذ فقط</label>
<div data-access-grade><input type="hidden" name="grade_level_id" value="{{ old('grade_level_id', $student->grade_level_id ?? $student->study_grade_level_id ?? '') }}"><x-step-tip>يصل الطالب إلى المواد المنشورة في صفه المسجّل أعلاه، وإلى الدروس الجديدة التي تُنشر فيها لاحقاً. صفوف أخرى والمحتوى المؤرشف تبقى محجوبة.</x-step-tip><p>الصف الحالي: <b data-access-grade-name>{{ $student->studyGradeLevel?->name ?? $student->gradeLevel?->name ?? 'اختر الصف من بيانات الطالب أعلاه' }}</b></p></div>
<div data-access-courses><x-step-tip>اختر المواد بالاسم. في هذا النمط لا يُمنح الطالب مواد صفه تلقائياً؛ تظهر له المواد المختارة فقط، حتى لو كانت من صفوف مختلفة.</x-step-tip><div class="checks">@forelse($courses as $course)<label><input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id, old('course_ids', isset($student) ? $student->courses->modelKeys() : [])))>{{ $course->title }} <small class="muted">{{ $course->gradeLevel?->name }}</small></label>@empty<p class="muted">أضف المواد أولاً ثم اختر مواد الطالب.</p>@endforelse</div></div>
</fieldset>
