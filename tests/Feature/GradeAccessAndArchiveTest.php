<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Services\LessonPublicationService;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeAccessAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_student_defaults_to_own_grade_and_cannot_access_another_grade_or_drafts(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $other = Course::factory()->for(GradeLevel::factory())->create();
        $draft = Course::factory()->for($grade)->create(['published_at' => null, 'publication_status' => 'draft']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.students.store'), ['name' => 'طالب الصف', 'study_grade_level_id' => $grade->id])->assertSessionHasNoErrors();
        $student = Student::where('name', 'طالب الصف')->sole();
        $this->assertSame($grade->id, $student->grade_level_id);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($course->title)->assertDontSee($other->title)->assertDontSee($draft->title);
        $this->get(route('student.course.show', $other))->assertNotFound();
        $foreignLesson = Lesson::factory()->for($other)->create();
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $foreignLesson->id])->assertNotFound();
        $future = Course::factory()->for($grade)->create();
        $this->get(route('student.learning'))->assertOk()->assertSee($future->title);
    }

    public function test_explicit_materials_override_grade_and_empty_selection_grants_no_materials(): void
    {
        $grade = GradeLevel::factory()->create();
        $own = Course::factory()->for($grade)->create();
        $selected = Course::factory()->for(GradeLevel::factory())->create();
        $student = Student::factory()->create(['study_grade_level_id' => $grade->id, 'grade_level_id' => $grade->id]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'courses', 'course_ids' => [$selected->id]])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->grade_level_id);
        $this->assertSame([$selected->id], $student->fresh()->accessibleCourses()->pluck('id')->all());
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($selected->title)->assertDontSee($own->title);
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'access_type' => 'courses'])->assertSessionHasNoErrors();
        $this->assertSame(0, $student->fresh()->accessibleCourses()->count());
    }

    public function test_changing_grade_updates_automatic_scope_without_carrying_old_courses(): void
    {
        $old = GradeLevel::factory()->create();
        $new = GradeLevel::factory()->create();
        $first = Course::factory()->for($old)->create();
        $second = Course::factory()->for($new)->create();
        $student = Student::factory()->create(['grade_level_id' => $old->id, 'study_grade_level_id' => $old->id]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->put(route('admin.students.update', $student), ['name' => $student->name, 'study_grade_level_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertSame($new->id, $student->fresh()->grade_level_id);
        $this->assertSame([$second->id], $student->fresh()->accessibleCourses()->pluck('id')->all());
    }

    public function test_lecture_alerts_follow_the_same_grade_or_custom_scope(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $auto = Student::factory()->create(['grade_level_id' => $grade->id]);
        $custom = Student::factory()->create(['study_grade_level_id' => $grade->id]);
        $custom->courses()->attach(Course::factory()->create());
        $foreign = Student::factory()->create(['grade_level_id' => GradeLevel::factory()->create()->id]);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'publication_status' => 'published', 'notify_students' => true]);
        app(LessonPublicationService::class)->notify($lesson);
        $this->assertSame(1, $auto->notifications()->count());
        $this->assertSame(0, $custom->notifications()->count());
        $this->assertSame(0, $foreign->notifications()->count());
    }

    public function test_backfill_preserves_custom_assignments_and_skips_archived_grades(): void
    {
        $grade = GradeLevel::factory()->create();
        $archived = GradeLevel::factory()->create();
        $archived->delete();
        $auto = Student::factory()->create(['study_grade_level_id' => $grade->id]);
        $custom = Student::factory()->create(['study_grade_level_id' => $grade->id]);
        $course = Course::factory()->create();
        $custom->courses()->attach($course);
        $blocked = Student::factory()->create(['study_grade_level_id' => $archived->id]);
        $migration = require database_path('migrations/2026_10_12_175630_enable_grade_access_for_students_without_selected_courses.php');
        $migration->up();
        $this->assertSame($grade->id, $auto->fresh()->grade_level_id);
        $this->assertNull($custom->fresh()->grade_level_id);
        $this->assertSame([$course->id], $custom->fresh()->accessibleCourses()->pluck('id')->all());
        $this->assertNull($blocked->fresh()->grade_level_id);
    }

    public function test_archive_lists_only_archived_items_and_preserves_archived_parent_names(): void
    {
        $grade = GradeLevel::factory()->create(['name' => 'صف مؤرشف']);
        $course = Course::factory()->for($grade)->create(['title' => 'مادة مؤرشفة']);
        $lesson = Lesson::factory()->for($course)->create(['title' => '<script>alert(1)</script>']);
        $active = Course::factory()->create(['title' => 'مادة نشطة']);
        $lesson->delete();
        $course->delete();
        $grade->delete();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.archive.index'))->assertOk()->assertSee($grade->name)->assertViewHas('counts', ['grade' => 1, 'course' => 1, 'lesson' => 1]);
        $this->get(route('admin.archive.index', ['type' => 'course']))->assertOk()->assertSee($course->title)->assertSee($grade->name)->assertDontSee($active->title);
        $this->get(route('admin.archive.index', ['type' => 'lesson']))->assertOk()->assertSee($lesson->title)->assertDontSee($lesson->title, false)->assertSee($course->title);
        $this->get(route('admin.archive.index', ['type' => 'course', 'q' => 'لا توجد']))->assertOk()->assertSee('لا توجد نتائج مؤرشفة');
    }

    public function test_archive_is_unavailable_to_students_and_unprivileged_admins(): void
    {
        $this->getJson(route('admin.archive.index'))->assertUnauthorized();
        $student = Student::factory()->create();
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->getJson(route('admin.archive.index'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->get(route('admin.archive.index'))->assertForbidden();
    }

    public function test_grade_access_never_exposes_archived_courses_or_lessons(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        $student = Student::factory()->create(['grade_level_id' => $grade->id]);
        $lesson->delete();
        $course->delete();
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertDontSee($course->title);
        $this->get(route('student.course.show', $course))->assertNotFound();
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])->assertNotFound();
    }

    public function test_teacher_progress_and_course_counts_include_automatic_grade_students(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        Lesson::factory()->for($course)->create();
        $auto = Student::factory()->create(['grade_level_id' => $grade->id]);
        $custom = Student::factory()->create();
        $custom->courses()->attach($course);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.courses.index'))->assertOk()->assertViewHas('courses', fn ($courses) => $courses->first()->students_count === 2);
        $this->get(route('admin.student-progress', ['course_id' => $course->id]))->assertOk()->assertSee($auto->name)->assertSee($custom->name);
        $this->put(route('admin.courses.students.sync', $course), ['student_ids' => [$auto->id]])->assertSessionHasErrors('student_ids.0');
    }
}
