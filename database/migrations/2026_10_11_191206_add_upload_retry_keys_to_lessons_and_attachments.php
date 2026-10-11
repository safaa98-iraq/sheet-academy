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
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('creation_key', 64)->nullable()->unique();
        });
        Schema::table('lesson_attachments', function (Blueprint $table): void {
            $table->uuid('client_upload_id')->nullable();
            $table->string('upload_checksum', 64)->nullable();
            $table->unique(['lesson_id', 'client_upload_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_attachments', function (Blueprint $table): void {
            $table->dropUnique(['lesson_id', 'client_upload_id']);
            $table->dropColumn(['client_upload_id', 'upload_checksum']);
        });
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropUnique(['creation_key']);
            $table->dropColumn('creation_key');
        });
    }
};
