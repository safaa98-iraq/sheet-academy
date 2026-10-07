<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('publication_status', 16)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
        });
        DB::table('courses')->whereNotNull('published_at')->update(['publication_status' => 'published']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['publication_status']);
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn(['publication_status', 'scheduled_at']);
        });
    }
};
