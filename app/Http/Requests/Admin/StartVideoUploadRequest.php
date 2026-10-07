<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartVideoUploadRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:10737418240'],
            'fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $metadata = [];
        foreach (explode(',', (string) $this->header('Upload-Metadata')) as $field) {
            [$name, $encodedValue] = array_pad(explode(' ', trim($field), 2), 2, '');
            if ($name !== '' && $encodedValue !== '') {
                $metadata[$name] = base64_decode($encodedValue, true) ?: null;
            }
        }

        return [
            'filename' => $metadata['filename'] ?? null,
            'fingerprint' => $metadata['fingerprint'] ?? null,
            'size' => $this->header('Upload-Length'),
        ];
    }

    public function messages(): array
    {
        return [
            'filename.required' => 'اختر ملف الفيديو أولاً.',
            'size.max' => 'الحد الأقصى لحجم الفيديو 10 جيجابايت.',
            'size.min' => 'ملف الفيديو فارغ.',
        ];
    }
}
