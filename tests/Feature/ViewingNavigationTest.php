<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ViewingNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_landing_issues_a_fresh_link_and_keeps_explicit_old_links_invalid(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create();
        $student->courses()->attach($course);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'is_published' => true, 'publication_status' => 'published']);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);

        $first = $this->get(route('student.lesson.show', $lesson))->assertRedirect()->headers->get('Location');
        $this->get($first)->assertOk()->assertSee($lesson->title);
        $next = $this->get(route('student.lesson.show', $lesson))->assertRedirect()->headers->get('Location');
        $this->get($first)->assertNotFound();
        $this->get($next)->assertOk();
        $this->getJson(route('student.lesson.show', $lesson))->assertNotFound();
    }

    public function test_closing_content_and_returning_through_history_landing_preserves_login(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create();
        $student->courses()->attach($course);
        $lesson = Lesson::factory()->for($course)->create(['type' => 'text', 'is_published' => true, 'publication_status' => 'published']);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->postJson(route('student.view-links.close'), ['view' => $query['view']])->assertOk();
        $this->get($url)->assertNotFound();
        $this->get('/learning')->assertOk();
        $fresh = $this->get(route('student.lesson.show', $lesson))->assertRedirect()->headers->get('Location');
        $this->get($fresh)->assertOk();
    }

    public function test_late_close_of_old_page_does_not_invalidate_the_new_page(): void
    {
        $student = Student::factory()->create();
        $lesson = Lesson::factory()->create(['type' => 'text', 'is_published' => true, 'publication_status' => 'published']);
        $student->courses()->attach($lesson->course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $old = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str(parse_url($old, PHP_URL_QUERY), $query);
        $fresh = $this->issueStudentViewLink('lesson', $lesson->id);

        $this->postJson(route('student.view-links.close'), ['view' => $query['view']])->assertOk();
        $this->get($fresh)->assertOk();
        $this->get($old)->assertNotFound();
    }

    public function test_attachment_landing_can_reopen_without_granting_foreign_content(): void
    {
        Storage::fake('private');
        $student = Student::factory()->create();
        $lesson = Lesson::factory()->create(['type' => 'text', 'is_published' => true, 'publication_status' => 'published']);
        $student->courses()->attach($lesson->course);
        Storage::disk('private')->put('page.png', 'image source');
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'page.png', 'mime_type' => 'image/png', 'original_name' => 'صورة', 'size_bytes' => 12]);
        $foreign = Lesson::factory()->create(['is_published' => true, 'publication_status' => 'published']);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);

        $fresh = $this->get(route('student.documents.show', $attachment))->assertRedirect()->headers->get('Location');
        $this->get($fresh)->assertOk()->assertSee('data-document-viewer', false);
        $this->get(route('student.lesson.show', $foreign))->assertNotFound();
        $this->get($fresh)->assertOk();
    }
}
