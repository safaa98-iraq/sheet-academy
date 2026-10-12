<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->hasPermissionTo('settings.manage') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'tagline' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'], 'contact_email' => ['nullable', 'email', 'max:190'], 'contact_phone' => ['nullable', 'string', 'regex:/\A[+0-9 ()-]{7,30}\z/'], 'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_logo' => ['sometimes', 'boolean'], 'subscription_reminder_enabled' => ['required', 'boolean'], 'subscription_reminder_days' => ['required', 'integer', 'between:1,30']];
    }

    public function messages(): array
    {
        return ['logo.mimes' => 'اللوغو يجب أن يكون صورة JPG أو PNG أو WebP.', 'logo.max' => 'الحد الأقصى للّوغو 5 ميغابايت.', 'name.required' => 'اسم الموقع مطلوب.', 'contact_phone.regex' => 'أدخل رقم تواصل صالحاً.', 'subscription_reminder_days.between' => 'اختر من يوم إلى 30 يوماً للتذكير.'];
    }
}
