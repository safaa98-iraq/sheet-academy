<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'tagline', 'description', 'logo_path', 'contact_phone', 'contact_email', 'subscription_reminder_enabled', 'subscription_reminder_days'];

    protected function casts(): array
    {
        return ['subscription_reminder_enabled' => 'boolean', 'subscription_reminder_days' => 'integer'];
    }
}
