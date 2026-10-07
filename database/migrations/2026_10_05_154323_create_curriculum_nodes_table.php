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
        Schema::create('curriculum_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('curriculum_nodes')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 16)->index();
            $table->string('title', 180);
            $table->unsignedInteger('position')->default(0);
            $table->string('publication_status', 16)->default('published')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['course_id', 'parent_id', 'position']);
        });

        foreach (DB::table('courses')->orderBy('id')->get() as $course) {
            $rootId = DB::table('curriculum_nodes')->insertGetId([
                'course_id' => $course->id,
                'parent_id' => null,
                'lesson_id' => null,
                'type' => 'material',
                'title' => $course->title,
                'position' => 0,
                'publication_status' => $course->published_at ? 'published' : 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $legacyLessons = DB::table('lessons')->where('course_id', $course->id)->orderBy('position')->orderBy('id')->get();
            if ($legacyLessons->isEmpty()) {
                continue;
            }

            $partId = DB::table('curriculum_nodes')->insertGetId(['course_id' => $course->id, 'parent_id' => $rootId, 'type' => 'part', 'title' => 'الجزء الأول', 'position' => 0, 'publication_status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $chapterId = DB::table('curriculum_nodes')->insertGetId(['course_id' => $course->id, 'parent_id' => $partId, 'type' => 'chapter', 'title' => 'الفصل الأول', 'position' => 0, 'publication_status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $sectionId = DB::table('curriculum_nodes')->insertGetId(['course_id' => $course->id, 'parent_id' => $chapterId, 'type' => 'section', 'title' => 'القسم الأول', 'position' => 0, 'publication_status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

            foreach ($legacyLessons as $lesson) {
                DB::table('curriculum_nodes')->insert([
                    'course_id' => $course->id,
                    'parent_id' => $sectionId,
                    'lesson_id' => $lesson->id,
                    'type' => 'lesson',
                    'title' => $lesson->title,
                    'position' => $lesson->position,
                    'publication_status' => $lesson->is_published ? 'published' : 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_nodes');
    }
};
