<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;

class SubscriptionExpiry implements ValidationRule
{
    public function __construct(private ?Carbon $currentExpiry = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isDateOnly = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) $value) === 1;
        if ($isDateOnly && $this->currentExpiry?->copy()->timezone('Asia/Baghdad')->toDateString() === $value) {
            return;
        }
        $expiry = $isDateOnly
            ? Carbon::parse($value, 'Asia/Baghdad')->endOfDay()->utc()
            : Carbon::parse($value);
        if ($this->currentExpiry?->equalTo($expiry)) {
            return;
        }
        if (! $expiry->isFuture()) {
            $fail('اختر تاريخ انتهاء اليوم أو بعده لتجديد الاشتراك.');
        }
    }
}
