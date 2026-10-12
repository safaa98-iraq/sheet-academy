<?php

namespace App\Http\Requests\Admin;

use App\Rules\SubscriptionExpiry;
use Illuminate\Foundation\Http\FormRequest;

class RenewStudentAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('update', $this->route('student')) ?? false;
    }

    public function rules(): array
    {
        return [
            'subscription_duration' => ['required', 'in:custom,unlimited'],
            'subscription_expires_at' => ['exclude_unless:subscription_duration,custom', 'bail', 'required', 'date_format:Y-m-d', new SubscriptionExpiry],
            'mode' => ['required', 'in:extend,replace'],
            'reactivate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['subscription_expires_at.required' => 'اختر تاريخ انتهاء الاشتراك.', 'subscription_expires_at.date_format' => 'اختر تاريخاً صالحاً من التقويم.', 'mode.in' => 'اختر تمديد الرمز الحالي أو استبداله.'];
    }
}
