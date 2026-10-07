<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadLessonAttachmentsRequest extends FormRequest
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
            'attachments' => ['required', 'array', 'min:1', 'max:10'],
            'attachments.*' => [
                'required', 'file', 'max:20480', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
                    if ($value instanceof UploadedFile && ! in_array($value->getMimeType(), $allowedMimes, true)) {
                        $fail('نوع الملف الحقيقي غير مسموح. ارفع ملف PDF أو صورة معتمدة.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.required' => 'اختر مرفقاً واحداً على الأقل.',
            'attachments.max' => 'يمكن إضافة عشرة مرفقات كحد أقصى في المرة الواحدة.',
            'attachments.*.max' => 'يجب ألا يتجاوز حجم كل مرفق ٢٠ ميغابايت.',
            'attachments.*.mimetypes' => 'المرفقات المسموحة هي PDF أو صور JPG وPNG وWebP.',
        ];
    }
}
