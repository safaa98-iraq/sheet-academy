<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->text('encrypted_token')->nullable();
        });
        Schema::table('students', function (Blueprint $table): void {
            $table->timestamp('subscription_reminded_at')->nullable();
            $table->timestamp('subscription_reminder_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn(['subscription_reminded_at', 'subscription_reminder_for']));
        Schema::table('student_tokens', fn (Blueprint $table) => $table->dropColumn('encrypted_token'));
    }
};
