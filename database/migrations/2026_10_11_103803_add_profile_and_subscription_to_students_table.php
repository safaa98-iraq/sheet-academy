<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('college', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('study_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->timestamp('subscription_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('study_grade_level_id');
            $table->dropColumn(['college', 'phone', 'subscription_expires_at']);
        });
    }
};
