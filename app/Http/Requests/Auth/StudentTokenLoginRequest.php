<?php

namespace App\Http\Requests\Auth;

use App\Services\StudentAuditService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StudentTokenLoginRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'رمز الدخول غير صالح أو منتهي.',
            'token.min' => str_starts_with((string) $this->input('token'), 'SH-')
                ? 'هذا رمز معاينة تجريبي. اطلب رمز دخول فعلياً من إدارة المنصة.'
                : 'رمز الدخول غير صالح أو منتهي.',
            'token.max' => 'رمز الدخول غير صالح أو منتهي.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        app(StudentAuditService::class)->recordFailedToken((string) $this->input('token', ''), $this);
        parent::failedValidation($validator);
    }
}
