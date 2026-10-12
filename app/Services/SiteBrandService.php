<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SiteBrandService
{
    public function current(): array
    {
        $data = Cache::remember('academy.site_brand', 3600, function (): array {
            $defaults = ['name' => 'عيادة التعلّم', 'tagline' => 'معرفة تصنع الفرق', 'description' => 'موادك ودروسك في مكان واحد، لتواصل التعلّم خطوة بخطوة.', 'logo_path' => null, 'contact_phone' => null, 'contact_email' => null, 'subscription_reminder_enabled' => false, 'subscription_reminder_days' => 7];
            if (! Schema::hasTable('site_settings')) {
                return $defaults;
            }

            return array_replace($defaults, SiteSetting::find(1)?->only(array_keys($defaults)) ?? []);
        });
        $data['logo_url'] = $data['logo_path'] ? route('branding.image', basename($data['logo_path'])) : null;

        return $data;
    }

    public function forget(): void
    {
        Cache::forget('academy.site_brand');
    }
}
