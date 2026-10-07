<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLessonProgressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('student') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::in(['started', 'heartbeat', 'paused', 'seeked', 'ended', 'unloaded'])],
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'is_playing' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'event.required' => 'تعذّر تحديد حالة المشاهدة.',
            'event.in' => 'حالة المشاهدة غير مدعومة.',
            'position_seconds.required' => 'موضع الفيديو مطلوب.',
            'position_seconds.integer' => 'موضع الفيديو غير صالح.',
            'position_seconds.min' => 'موضع الفيديو لا يمكن أن يكون سالباً.',
            'position_seconds.max' => 'موضع الفيديو يتجاوز الحد المسموح.',
            'is_playing.required' => 'حالة التشغيل مطلوبة.',
            'is_playing.boolean' => 'حالة التشغيل غير صالحة.',
        ];
    }
}
