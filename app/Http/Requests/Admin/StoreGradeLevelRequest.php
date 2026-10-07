<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGradeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->hasPermissionTo('courses.manage') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('grade_levels')->ignore($this->route('grade_level'))],
            'position' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المرحلة الصفية مطلوب.',
            'name.unique' => 'هذه المرحلة الصفية موجودة بالفعل.',
            'name.max' => 'اسم المرحلة يجب ألا يتجاوز 100 حرف.',
            'position.required' => 'ترتيب المرحلة مطلوب.',
            'position.integer' => 'ترتيب المرحلة يجب أن يكون عدداً صحيحاً.',
            'position.min' => 'ترتيب المرحلة يجب أن يكون بين 0 و1000.',
            'position.max' => 'ترتيب المرحلة يجب أن يكون بين 0 و1000.',
        ];
    }
}
