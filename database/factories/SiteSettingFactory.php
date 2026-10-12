<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SiteSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'أكاديمية طب الأسنان', 'tagline' => 'تعلّم بثقة', 'subscription_reminder_enabled' => false, 'subscription_reminder_days' => 7];
    }
}
