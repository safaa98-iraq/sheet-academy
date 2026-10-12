<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->timestamp('subscription_expiry_notified_at')->nullable();
            $table->index(['subscription_expiry_notified_at', 'subscription_expires_at'], 'students_expiry_alert_index');
        });
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->timestamp('expiry_notified_at')->nullable();
            $table->index(['expiry_notified_at', 'expires_at'], 'student_tokens_expiry_alert_index');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropIndex('students_expiry_alert_index');
            $table->dropColumn('subscription_expiry_notified_at');
        });
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->dropIndex('student_tokens_expiry_alert_index');
            $table->dropColumn('expiry_notified_at');
        });
    }
};
