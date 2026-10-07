<?php

return [
    'accepted' => 'يجب الموافقة على :attribute.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'between' => [
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'string' => 'يجب أن يتراوح طول :attribute بين :min و:max حرفاً.',
        'array' => 'يجب أن تحتوي :attribute على :min إلى :max عنصراً.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'date' => 'أدخل تاريخاً صحيحاً في :attribute.',
    'email' => 'أدخل بريداً إلكترونياً صحيحاً.',
    'exists' => 'القيمة المحددة في :attribute غير موجودة.',
    'in' => 'القيمة المحددة في :attribute غير صالحة.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'max' => [
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :max كيلوبايت.',
        'string' => 'يجب ألا يتجاوز :attribute :max حرفاً.',
        'array' => 'يجب ألا تحتوي :attribute على أكثر من :max عنصراً.',
    ],
    'min' => [
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفاً.',
        'array' => 'يجب ألا تحتوي :attribute على أقل من :min عنصراً.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => 'قيمة :attribute مسجلة مسبقاً.',
    'attributes' => [
        'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور',
        'token' => 'رمز الوصول', 'title' => 'العنوان', 'course_ids' => 'المواد',
        'video_quality' => 'جودة الفيديو', 'playback_speed' => 'سرعة التشغيل',
    ],
];
