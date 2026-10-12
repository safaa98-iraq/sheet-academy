<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncCourseStudentsRequest extends FormRequest
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
            'student_ids' => ['sometimes', 'array', 'max:1000'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists('students', 'id')->whereNull('grade_level_id')],
        ];
    }

    public function messages(): array
    {
        return [
            'student_ids.max' => 'لا يمكن ربط أكثر من ألف طالب في عملية واحدة.',
            'student_ids.*.exists' => 'اختر طلاب نمط المواد المحددة فقط؛ طلاب الصف يصلون إلى مواده تلقائياً.',
        ];
    }
}
