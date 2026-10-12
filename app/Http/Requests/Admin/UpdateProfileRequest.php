<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('web')?->is_super_admin ?? false;
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
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->user('web'))],
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
            'is_super_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'role_ids' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسمك.',
            'name.max' => 'يجب ألا يتجاوز الاسم 150 حرفاً.',
            'email.required' => 'اكتب البريد الإلكتروني الذي تستخدمه للدخول.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد مسجّل لحساب آخر.',
            'current_password.required' => 'أدخل كلمة المرور الحالية لتأكيد التغيير.',
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
            'password.min' => 'يجب ألا تقل كلمة المرور الجديدة عن 12 حرفاً.',
            'password.confirmed' => 'تأكيد كلمة المرور الجديدة غير مطابق.',
            'is_super_admin.prohibited' => 'لا يمكن تغيير صلاحيات الحساب من هذه الصفحة.',
            'is_active.prohibited' => 'لا يمكن تعطيل الحساب من هذه الصفحة.',
            'role_ids.prohibited' => 'لا يمكن تغيير صلاحيات الحساب من هذه الصفحة.',
        ];
    }
}
