@php($accessType = old('access_type', isset($student) && $student->grade_level_id ? 'grade' : 'courses'))
<fieldset class="student-access" data-student-access>
    <legend>نطاق الوصول للمحتوى</legend>
    <label><input type="radio" name="access_type" value="grade" @checked($accessType==='grade')> <x-icon name="layers"/> جميع مواد ودروس مرحلة دراسية</label>
    <label><input type="radio" name="access_type" value="courses" @checked($accessType==='courses')> <x-icon name="book"/> مواد معينة فقط</label>
    <div data-access-grade @if($accessType!=='grade') hidden @endif>
        <label>المرحلة الدراسية<select name="grade_level_id"><option value="">اختر المرحلة</option>@foreach($gradeLevels as $grade)<option value="{{ $grade->id }}" @selected((string) old('grade_level_id', $student->grade_level_id ?? '') === (string) $grade->id)>{{ $grade->name }}</option>@endforeach</select></label>
        <p class="muted">يشمل كل المواد والدروس المنشورة في المرحلة، بما فيها ما يُضاف لاحقاً. تبقى المسودات مخفية.</p>
    </div>
    <div data-access-courses @if($accessType!=='courses') hidden @endif>
        <p class="muted">اختر المواد المتاحة للطالب. يمكن اختيار مواد من مراحل مختلفة، ولن تظهر له المواد الأخرى.</p>
        <div class="checks">@forelse($courses as $course)<label><input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id,old('course_ids',isset($student)?$student->courses->modelKeys():[])))> {{ $course->title }} <small class="muted">{{ $course->gradeLevel?->name }}</small></label>@empty<p class="muted">أضف المواد أولاً لتتمكن من تحديدها هنا.</p>@endforelse</div>
    </div>
</fieldset>
