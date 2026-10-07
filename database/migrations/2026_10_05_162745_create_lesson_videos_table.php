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
        Schema::create('lesson_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('not_uploaded')->index();
            $table->string('source_path')->nullable();
            $table->string('source_mime', 100)->nullable();
            $table->unsignedBigInteger('source_size')->nullable();
            $table->unsignedInteger('source_width')->nullable();
            $table->unsignedInteger('source_height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('output_path')->nullable();
            $table->string('key_path')->nullable();
            $table->string('key_fingerprint', 64)->nullable();
            $table->json('available_resolutions')->nullable();
            $table->json('enabled_resolutions')->nullable();
            $table->uuid('job_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_videos');
    }
};
