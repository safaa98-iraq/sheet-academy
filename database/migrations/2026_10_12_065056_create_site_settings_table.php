<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->default('عيادة التعلّم');
            $table->string('tagline', 150)->default('معرفة تصنع الفرق');
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->boolean('subscription_reminder_enabled')->default(false);
            $table->unsignedTinyInteger('subscription_reminder_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
