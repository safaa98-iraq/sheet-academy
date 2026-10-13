<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('bound_device_hash', 64)->nullable();
            $table->string('bound_device_user_agent', 255)->nullable();
            $table->timestamp('bound_device_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn(['bound_device_hash', 'bound_device_user_agent', 'bound_device_at']);
        });
    }
};
