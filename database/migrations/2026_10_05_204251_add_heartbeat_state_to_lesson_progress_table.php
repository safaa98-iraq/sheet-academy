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
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->timestamp('last_heartbeat_at')->nullable()->after('completed_at');
            $table->boolean('is_playing')->default(false)->after('last_heartbeat_at');
            $table->index(['student_id', 'updated_at']);
            $table->index(['lesson_id', 'watched_seconds']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'updated_at']);
            $table->dropIndex(['lesson_id', 'watched_seconds']);
            $table->dropColumn(['last_heartbeat_at', 'is_playing']);
        });
    }
};
