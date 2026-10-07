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
            'email' => ['nullable', 'email', 'max:190', Rule::unique('students', 'email')],
            'access_type' => ['sometimes', 'required', Rule::in(['grade', 'courses'])],
            'grade_level_id' => ['exclude_unless:access_type,grade', 'required', 'integer', 'exists:grade_levels,id'],
            'course_ids' => ['exclude_if:access_type,grade', 'sometimes', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'device_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'access_type.in' => 'اختر الوصول إلى مرحلة كاملة أو مواد محددة.',
            'grade_level_id.required' => 'اختر المرحلة الدراسية المطلوب إتاحتها.',
            'grade_level_id.exists' => 'المرحلة الدراسية غير موجودة.',
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
