<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
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
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'is_published' => ['sometimes', 'boolean'],
            'publication_status' => ['required_without:is_published', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date', 'required_if:publication_status,scheduled', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'grade_level_id.required' => 'اختر المرحلة الصفية للمادة.',
            'grade_level_id.exists' => 'المرحلة الصفية المحددة غير موجودة.',
            'title.required' => 'عنوان المادة مطلوب.', 'title.max' => 'عنوان المادة طويل جداً.',
            'publication_status.required_without' => 'حدد حالة نشر المادة.',
            'publication_status.in' => 'حالة نشر المادة غير صالحة.',
            'scheduled_at.required_if' => 'حدد موعد نشر المادة.',
            'scheduled_at.after' => 'موعد النشر يجب أن يكون في المستقبل.',
        ];
    }
}
