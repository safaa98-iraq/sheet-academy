<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'college' => ['sometimes', 'required', 'string', 'max:190'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', 'regex:/\A[+0-9 ()-]{7,30}\z/'],
            'study_grade_level_id' => ['sometimes', 'required', 'integer', 'exists:grade_levels,id'],
            'subscription_duration' => ['sometimes', 'required', Rule::in(['unlimited', '30', '90', '180', '365', 'custom'])],
            'subscription_expires_at' => ['nullable', 'required_if:subscription_duration,custom', 'date', 'after:now'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('students', 'email')],
            'access_type' => ['sometimes', 'required', Rule::in(['grade', 'courses'])],
            'grade_level_id' => ['exclude_unless:access_type,grade', 'required', 'integer', 'exists:grade_levels,id'],
            'course_ids' => ['exclude_if:access_type,grade', 'sometimes', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'device_limit' => ['nullable', 'integer', 'in:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'access_type.in' => 'اختر الوصول إلى مرحلة كاملة أو مواد محددة.',
            'grade_level_id.required' => 'اختر المرحلة الدراسية المطلوب إتاحتها.',
            'grade_level_id.exists' => 'المرحلة الدراسية غير موجودة.',
            'college.required' => 'اسم الكلية مطلوب.',
            'phone.required' => 'رقم الهاتف الشخصي مطلوب.',
            'phone.regex' => 'أدخل رقم هاتف صحيحاً، مثل +9647701234567.',
            'study_grade_level_id.required' => 'اختر المرحلة الدراسية للطالب.',
            'subscription_expires_at.after' => 'اختر تاريخ انتهاء في المستقبل لتجديد الاشتراك.',
            'name.required' => 'حقل اسم الطالب مطلوب.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد مسجّل لطالب آخر.',
            'course_ids.*.exists' => 'إحدى المواد المختارة غير موجودة.',
            'expires_at.after' => 'يجب أن يكون تاريخ انتهاء الرمز في المستقبل.',
            'device_limit.min' => 'يجب السماح بجهاز واحد على الأقل.',
            'device_limit.max' => 'لا يمكن ربط أكثر من عشرة أجهزة برمز الدخول.',
        ];
    }
}
