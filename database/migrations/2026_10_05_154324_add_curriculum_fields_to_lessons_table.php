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
        Schema::table('lessons', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('publication_status', 16)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('video_reference')->nullable();
            $table->string('video_status', 20)->default('not_uploaded')->index();
            $table->json('available_resolutions')->nullable();
            $table->softDeletes();
        });
        DB::table('lessons')->where('is_published', true)->update(['publication_status' => 'published']);
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->index();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['position']);
            $table->dropColumn('position');
        });
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['publication_status']);
            $table->dropIndex(['scheduled_at']);
            $table->dropIndex(['video_status']);
            $table->dropColumn(['description', 'body_html', 'publication_status', 'scheduled_at', 'video_reference', 'video_status', 'available_resolutions']);
        });
    }
};
