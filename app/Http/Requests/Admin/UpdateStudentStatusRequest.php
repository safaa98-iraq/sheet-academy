<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentStatusRequest extends FormRequest
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
        return ['status' => ['required', 'in:active,frozen,suspended']];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'حدد حالة حساب الطالب.',
            'status.in' => 'حالة الطالب المحددة غير صالحة.',
        ];
    }
}
