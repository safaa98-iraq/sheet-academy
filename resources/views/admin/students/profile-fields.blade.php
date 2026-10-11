<x-step-tip>اكتب الاسم الكامل كما تريد ظهوره على الفيديو، وأدخل الكلية والهاتف والمرحلة الدراسية.</x-step-tip>
<div class="profile-fields-grid">
<label for="name">اسم الطالب الكامل<input id="name" name="name" value="{{ old('name', $student->name ?? '') }}" required maxlength="150" autocomplete="name"></label>
<label for="college">الكلية<input id="college" name="college" value="{{ old('college', $student->college ?? '') }}" required maxlength="190" placeholder="مثلاً: كلية طب الأسنان — جامعة بغداد"></label>
<label for="phone">رقم الهاتف الشخصي<input id="phone" name="phone" type="tel" dir="ltr" value="{{ old('phone', $student->phone ?? '') }}" required maxlength="30" placeholder="+9647701234567" autocomplete="tel"></label>
<label for="study-grade">المرحلة الدراسية<select id="study-grade" name="study_grade_level_id" required><option value="">اختر المرحلة</option>@foreach($gradeLevels as $grade)<option value="{{ $grade->id }}" @selected((string) old('study_grade_level_id', $student->study_grade_level_id ?? $student->grade_level_id ?? '') === (string) $grade->id)>{{ $grade->name }}</option>@endforeach</select></label>
<label for="email">البريد الإلكتروني <span class="muted">(اختياري)</span><input id="email" name="email" type="email" dir="ltr" value="{{ old('email', $student->email ?? '') }}" autocomplete="email"></label>
</div>
