<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StudentProgressCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        Cache::purge('database');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
    }

    public function test_progress_statistics_survive_repeated_loads_from_database_cache(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'محاضرة الإحصاء', 'publication_status' => 'published']);
        $student->courses()->attach($course);
        LessonProgress::query()->create([
            'student_id' => $student->id, 'lesson_id' => $lesson->id,
            'watched_seconds' => 3600, 'last_position_seconds' => 42,
        ]);

        $url = route('admin.student-progress', ['course_id' => $course->id]);
        $this->get($url)->assertOk()->assertSee('01:00')->assertSee('00:42');
        Cache::purge('database');
        $this->get($url)->assertOk()->assertSee($lesson->title)->assertSee($student->name)
            ->assertSee('01:00')->assertSee('00:42');
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'watched_seconds' => 3600]);
    }

    public function test_old_incompatible_cached_statistics_do_not_break_the_page(): void
    {
        Cache::put('admin.student-progress.stats.v1', [
            'active_students' => 0, 'average_completion' => 0,
            'most_watched' => collect([(object) ['title' => 'Old cached lesson', 'watched_seconds' => 30]]),
            'drop_off' => collect(),
        ], now()->addMinutes(2));
        Cache::purge('database');

        $this->get(route('admin.student-progress'))->assertOk()->assertDontSee('Old cached lesson');
        Cache::purge('database');
        $this->get(route('admin.student-progress'))->assertOk();
    }
}
