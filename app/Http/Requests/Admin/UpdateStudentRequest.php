<?php

namespace App\Http\Requests\Admin;

use App\Rules\SubscriptionExpiry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $student = $this->route('student');

        return [
            'name' => ['required', 'string', 'max:150'],
            'college' => ['sometimes', 'required', 'string', 'max:190'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', 'regex:/\A[+0-9 ()-]{7,30}\z/'],
            'study_grade_level_id' => ['sometimes', 'required', 'integer', 'exists:grade_levels,id'],
            'subscription_duration' => ['sometimes', 'required', Rule::in(['unlimited', '30', '90', '180', '365', 'custom'])],
            'subscription_expires_at' => ['exclude_unless:subscription_duration,custom', 'bail', 'required', 'date', new SubscriptionExpiry($student->subscription_expires_at)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('students', 'email')->ignore($student)],
            'access_type' => ['sometimes', 'required', Rule::in(['courses'])],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'access_type.in' => 'حدد المواد المشترك بها الطالب؛ المرحلة الدراسية لا تمنح وصولاً تلقائياً.',
            'grade_level_id.required' => 'اختر المرحلة الدراسية المطلوب إتاحتها.',
            'grade_level_id.exists' => 'المرحلة الدراسية غير موجودة.',
            'college.required' => 'اسم الكلية مطلوب.',
            'phone.required' => 'رقم الهاتف الشخصي مطلوب.',
            'phone.regex' => 'أدخل رقم هاتف صحيحاً، مثل +9647701234567.',
            'study_grade_level_id.required' => 'اختر المرحلة الدراسية للطالب.',
            'subscription_expires_at.required' => 'اختر تاريخ انتهاء الاشتراك من التقويم.',
            'subscription_expires_at.date' => 'أدخل تاريخ انتهاء صحيحاً.',
            'name.required' => 'حقل اسم الطالب مطلوب.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد مسجّل لطالب آخر.',
            'course_ids.*.exists' => 'إحدى المواد المختارة غير موجودة.',
        ];
    }
}
