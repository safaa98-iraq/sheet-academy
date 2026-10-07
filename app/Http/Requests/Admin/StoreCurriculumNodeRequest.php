<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCurriculumNodeRequest extends FormRequest
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
            'parent_id' => ['required', 'integer', 'exists:curriculum_nodes,id'],
            'type' => ['required', 'in:part,chapter,section,lesson'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'body_html' => ['nullable', 'string', 'max:100000'],
            'content_type' => ['required_if:type,lesson', 'nullable', 'in:video,text,file'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'publication_status' => ['required', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date', 'required_if:publication_status,scheduled', 'after:now'],
            'video_reference' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_id.required' => 'اختر المستوى الأب للمحتوى.',
            'type.required' => 'نوع مستوى المحتوى مطلوب.',
            'type.in' => 'نوع المستوى غير صالح.',
            'title.required' => 'عنوان المحتوى مطلوب.',
            'content_type.required_if' => 'حدد نوع محتوى الدرس.',
            'content_type.in' => 'نوع الدرس غير صالح.',
            'publication_status.required' => 'حدد حالة النشر.',
            'publication_status.in' => 'حالة النشر غير صالحة.',
            'scheduled_at.required_if' => 'حدد موعد نشر الدرس المجدول.',
            'scheduled_at.after' => 'موعد النشر المجدول يجب أن يكون في المستقبل.',
        ];
    }
}
