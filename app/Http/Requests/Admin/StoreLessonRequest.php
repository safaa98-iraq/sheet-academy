<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:video,text,file,document,pdf,image'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:12000'],
            'body_html' => ['nullable', 'string', 'max:100000'],
            'publication_status' => ['sometimes', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date', 'required_if:publication_status,scheduled', 'after:now'],
            'video_reference' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الدرس مطلوب.', 'type.in' => 'نوع الدرس غير صالح.',
            'duration_seconds.integer' => 'أدخل مدة صحيحة بالثواني.',
            'publication_status.in' => 'حالة النشر غير صالحة.',
            'scheduled_at.after' => 'موعد النشر يجب أن يكون في المستقبل.',
        ];
    }
}
