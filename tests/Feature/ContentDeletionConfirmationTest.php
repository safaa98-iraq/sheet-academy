<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentDeletionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_shows_the_exact_scope_and_escaped_name_without_deleting_anything(): void
    {
        $grade = GradeLevel::factory()->create(['name' => '<script>alert(1)</script>']);
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'attachment.pdf', 'original_name' => 'ملزمة.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->get(route('admin.grade-levels.delete', $grade))->assertOk()->assertSee($grade->name)->assertDontSee($grade->name, false)->assertSee('قرأت العواقب')->assertSee('لا يوجد زر استرجاع')->assertViewHas('preview', fn ($p) => $p['courses'] === 1 && $p['lessons'] === 1 && $p['attachments'] === 1 && $p['students'] === 1);
        $this->assertModelExists($grade);
        $this->assertModelExists($course);
        $this->assertModelExists($lesson);
        $this->assertModelExists($attachment);
    }

    public function test_deletion_requires_a_prior_preview_and_explicit_acknowledgement(): void
    {
        $course = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->delete(route('admin.courses.destroy', $course))->assertSessionHasErrors('confirmed');
        $this->delete(route('admin.courses.destroy', $course), ['confirmed' => true, 'confirmation' => str_repeat('a', 64)])->assertSessionHasErrors('confirmation');
        $preview = $this->get(route('admin.courses.delete', $course))->assertOk();
        $this->delete(route('admin.courses.destroy', $course), ['confirmed' => false, 'confirmation' => $preview->viewData('nonce')])->assertSessionHasErrors('confirmed');
        $this->assertFalse($course->fresh()->trashed());
    }

    public function test_deleting_a_whole_grade_archives_its_content_but_preserves_students_progress_files_and_other_courses(): void
    {
        Storage::fake('private');
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        $other = Course::factory()->for(GradeLevel::factory())->create();
        $student = Student::factory()->create(['study_grade_level_id' => $grade->id]);
        $student->courses()->attach([$course->id, $other->id]);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 44]);
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'attachment.pdf', 'original_name' => 'ملزمة.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4]);
        Storage::disk('private')->put($attachment->path, 'kept');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.grade-levels.delete', $grade))->assertOk();
        $this->delete(route('admin.grade-levels.destroy', $grade), ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')])->assertRedirect(route('admin.grade-levels.index'));
        $this->assertSoftDeleted($grade);
        $this->assertSoftDeleted($course);
        $this->assertSoftDeleted($lesson);
        $this->assertFalse($other->fresh()->trashed());
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame($grade->name, $student->fresh()->studyGradeLevel->name);
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'last_position_seconds' => 44]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'content_deleted_by_admin']);
        Storage::disk('private')->assertExists($attachment->path);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.learning'))->assertOk()->assertSee($other->title)->assertDontSee($course->title);
        $this->get(route('student.course.show', $course))->assertNotFound();
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $lesson->id])->assertNotFound();
    }

    public function test_course_deletion_archives_all_its_lessons_and_curriculum_only(): void
    {
        $course = Course::factory()->create();
        $lessons = Lesson::factory()->for($course)->count(2)->create();
        $node = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => 'المادة', 'publication_status' => 'published', 'position' => 0]);
        $other = Lesson::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.courses.delete', $course))->assertOk();
        $this->delete(route('admin.courses.destroy', $course), ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')])->assertRedirect(route('admin.courses.index'));
        $this->assertSoftDeleted($course);
        $this->assertSoftDeleted($node);
        foreach ($lessons as $lesson) {
            $this->assertSoftDeleted($lesson);
        }
        $this->assertFalse($other->fresh()->trashed());
    }

    public function test_lesson_deletion_preserves_its_course_siblings_and_progress(): void
    {
        $lesson = Lesson::factory()->create();
        $sibling = Lesson::factory()->for($lesson->course)->create();
        $student = Student::factory()->create();
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 37]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.lessons.delete', $lesson))->assertOk();
        $this->delete(route('admin.lessons.destroy', $lesson), ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')])->assertRedirect(route('admin.courses.edit', $lesson->course));
        $this->assertSoftDeleted($lesson);
        $this->assertFalse($sibling->fresh()->trashed());
        $this->assertFalse($lesson->course->fresh()->trashed());
        $this->assertDatabaseHas('lesson_progress', ['lesson_id' => $lesson->id, 'last_position_seconds' => 37]);
    }

    public function test_changed_scope_and_a_nonce_from_another_course_cannot_be_confirmed(): void
    {
        $first = Course::factory()->create();
        $second = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.courses.delete', $first))->assertOk();
        $data = ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')];
        $this->delete(route('admin.courses.destroy', $second), $data)->assertSessionHasErrors('confirmation');
        Lesson::factory()->for($first)->create();
        $this->delete(route('admin.courses.destroy', $first), $data)->assertSessionHasErrors('confirmation');
        $this->assertFalse($first->fresh()->trashed());
        $this->assertFalse($second->fresh()->trashed());
    }

    public function test_preview_and_delete_require_management_permission(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create();
        $lesson = Lesson::factory()->for($course)->create();
        foreach ([['grade-levels', $grade], ['courses', $course], ['lessons', $lesson]] as [$type, $content]) {
            $this->get(route('admin.'.$type.'.delete', $content))->assertRedirect(route('admin.login'));
        }
        $this->actingAs(User::factory()->create(), 'web');
        foreach ([['grade-levels', $grade], ['courses', $course], ['lessons', $lesson]] as [$type, $content]) {
            $this->get(route('admin.'.$type.'.delete', $content))->assertForbidden();
            $this->delete(route('admin.'.$type.'.destroy', $content), ['confirmed' => true])->assertForbidden();
        }
        $this->assertFalse($grade->fresh()->trashed());
    }

    public function test_old_confirmation_expires_and_does_not_delete_content(): void
    {
        $this->freezeTime();
        $course = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.courses.delete', $course))->assertOk();
        $this->travel(11)->minutes();
        $this->delete(route('admin.courses.destroy', $course), ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')])->assertSessionHasErrors('confirmation');
        $this->assertFalse($course->fresh()->trashed());
    }

    public function test_an_archived_grade_cannot_receive_new_courses(): void
    {
        $grade = GradeLevel::factory()->create();
        $grade->delete();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post(route('admin.courses.store'), ['grade_level_id' => $grade->id, 'title' => 'مادة جديدة', 'publication_status' => 'draft'])->assertSessionHasErrors('grade_level_id');
        $this->assertSame(0, Course::count());
    }
}
