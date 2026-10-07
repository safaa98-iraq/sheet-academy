<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_devices', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('device_name');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->char('view_link_hash', 64)->nullable()->after('session_id');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedInteger('violation_score')->default(0);
            $table->timestamp('content_agreement_accepted_at')->nullable();
            $table->string('content_agreement_version', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['violation_score', 'content_agreement_accepted_at', 'content_agreement_version']);
        });
        Schema::table('student_devices', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'view_link_hash']);
        });
    }
};
