<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublisherUploadRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_after_lost_creation_response_returns_same_draft_without_duplicate_files(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $payload = ['creation_key' => 'e8f8c88a-b8dc-4a73-87c4-fbb16ba5a92d', 'title' => 'رفع على اتصال بطيء', 'type' => 'text', 'publication_status' => 'draft', 'attachments' => [UploadedFile::fake()->image('lecture.png')]];

        $first = $this->post(route('admin.courses.lessons.store', $course), $payload, ['Accept' => 'application/json'])->assertCreated();
        $retry = $this->post(route('admin.courses.lessons.store', $course), $payload, ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame($first->json('lesson_id'), $retry->json('lesson_id'));
        $this->assertSame(1, $course->lessons()->count());
        $lesson = $course->lessons()->sole();
        $this->assertSame(1, $lesson->attachments()->count());
        $this->assertFalse($lesson->isVisibleToStudents());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_completed_attachment_retry_is_idempotent_and_other_files_are_preserved_until_final_publish(): void
    {
        Storage::fake('private');
        $lesson = Lesson::factory()->create(['publication_status' => 'draft', 'is_published' => false]);
        $student = Student::factory()->create();
        $student->courses()->attach($lesson->course);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $payload = ['client_upload_id' => '16eb8e53-e349-585f-b795-e7046a75eb78', 'attachments' => [UploadedFile::fake()->image('slide.jpg')]];

        $this->post(route('admin.lessons.attachments.store', $lesson), $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post(route('admin.lessons.attachments.store', $lesson), $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post(route('admin.lessons.attachments.store', $lesson), ['client_upload_id' => '4f7b2021-d15d-52fb-b8df-930eeaf3e261', 'attachments' => [UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.4\n%%EOF")]], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(2, $lesson->attachments()->count());
        $this->assertCount(2, Storage::disk('private')->allFiles());
        $this->assertSame(0, $student->notifications()->count());
        $this->putJson(route('admin.lessons.update', $lesson), ['title' => $lesson->title, 'type' => 'text', 'publication_status' => 'published', 'notify_students' => true])->assertOk();
        $this->assertSame(1, $student->notifications()->count());
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('slide.jpg')->assertSee('guide.pdf');
    }

    public function test_reusing_attachment_identity_with_changed_bytes_is_rejected_without_replacing_original(): void
    {
        Storage::fake('private');
        $lesson = Lesson::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $key = '16eb8e53-e349-585f-b795-e7046a75eb78';
        $url = route('admin.lessons.attachments.store', $lesson);
        $this->post($url, ['client_upload_id' => $key, 'attachments' => [UploadedFile::fake()->image('slide.png', 100, 100)]], ['Accept' => 'application/json'])->assertOk();
        $path = $lesson->attachments()->sole()->path;

        $this->post($url, ['client_upload_id' => $key, 'attachments' => [UploadedFile::fake()->image('slide.png', 200, 200)]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'تغيّر محتوى الملف أثناء استئناف الرفع.');

        $this->assertSame($path, $lesson->attachments()->sole()->path);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_retry_identity_does_not_bypass_authorization_or_file_validation(): void
    {
        Storage::fake('private');
        $lesson = Lesson::factory()->create();
        $url = route('admin.lessons.attachments.store', $lesson);
        $this->actingAs(User::factory()->create(), 'web')->post($url, ['client_upload_id' => '16eb8e53-e349-585f-b795-e7046a75eb78', 'attachments' => [UploadedFile::fake()->image('slide.png')]], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->post($url, ['client_upload_id' => 'invalid', 'attachments' => [UploadedFile::fake()->image('slide.png')]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('client_upload_id');
        $this->post($url, ['client_upload_id' => '16eb8e53-e349-585f-b795-e7046a75eb78', 'attachments' => [UploadedFile::fake()->createWithContent('fake.webp', '<?php echo "bad";')]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->post($url, ['client_upload_id' => '16eb8e53-e349-585f-b795-e7046a75eb78', 'attachments' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->assertSame(0, $lesson->attachments()->count());
        $this->assertEmpty(Storage::disk('private')->allFiles());
    }

    public function test_creation_identity_is_scoped_to_author_and_course_and_cannot_restore_deleted_lessons(): void
    {
        $courses = Course::factory()->count(2)->create();
        $author = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($author, 'web');
        $payload = ['creation_key' => 'e8f8c88a-b8dc-4a73-87c4-fbb16ba5a92d', 'title' => 'محاضرة', 'type' => 'text', 'publication_status' => 'draft'];
        $first = $this->postJson(route('admin.courses.lessons.store', $courses[0]), $payload)->assertCreated();
        $this->postJson(route('admin.courses.lessons.store', $courses[1]), $payload)->assertCreated();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->postJson(route('admin.courses.lessons.store', $courses[0]), $payload)->assertCreated();
        $this->assertSame(3, Lesson::count());
        Lesson::findOrFail($first->json('lesson_id'))->delete();

        $this->actingAs($author, 'web')->postJson(route('admin.courses.lessons.store', $courses[0]), $payload)->assertConflict();

        $this->assertSame(3, Lesson::withTrashed()->count());
    }

    public function test_browser_viewer_receives_smaller_watermarked_image_with_private_signed_access(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['publication_status' => 'published', 'is_published' => true]);
        $image = imagecreatetruecolor(800, 1000);
        $colorState = 71;
        for ($y = 0; $y < 1000; $y++) {
            for ($x = 0; $x < 800; $x++) {
                $colorState = ($colorState * 1664525 + 1013904223) & 0xFFFFFFFF;
                imagesetpixel($image, $x, $y, ($colorState >> 8) & 0xFFFFFF);
            }
        }
        ob_start();
        imagepng($image);
        $source = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('private')->put('lessons/large.png', $source);
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'lessons/large.png', 'original_name' => 'large.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($source)]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        parse_str(parse_url($this->issueStudentViewLink('document', $attachment->id), PHP_URL_QUERY), $query);
        $url = URL::temporarySignedRoute('student.documents.page', now()->addMinutes(5), ['attachment' => $attachment->id, 'page' => 1, 'view' => $query['view']]);

        $original = $this->get($url, ['Accept' => 'image/png'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $response = $this->get($url, ['Accept' => 'image/jpeg,image/*'])->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Vary', 'Accept')->assertHeader('Content-Disposition', 'inline');

        $this->assertLessThanOrEqual(strlen($original->getContent()), strlen($response->getContent()));
        $this->assertSame(800, getimagesizefromstring($response->getContent())[0]);
        $this->assertNotSame(hash('sha256', $source), hash('sha256', $response->getContent()));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get($url.'broken', ['Accept' => 'image/jpeg'])->assertForbidden();
    }

    public function test_webp_attachment_opens_in_protected_viewer_without_php_webp_extension(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['publication_status' => 'published', 'is_published' => true]);
        $webp = base64_decode('UklGRjIAAABXRUJQVlA4ICYAAADQAgCdASogACAAPpFEnUqlo6KhqAgAsBIJaQAAPaOgAP74TQAAAA==');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->post(route('admin.lessons.attachments.store', $lesson), ['attachments' => [UploadedFile::fake()->createWithContent('diagram.webp', $webp)]], ['Accept' => 'application/json'])->assertOk();
        $attachment = $lesson->attachments()->sole();
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        parse_str(parse_url($this->issueStudentViewLink('document', $attachment->id), PHP_URL_QUERY), $query);
        $url = URL::temporarySignedRoute('student.documents.page', now()->addMinutes(5), ['attachment' => $attachment->id, 'page' => 1, 'view' => $query['view']]);

        $response = $this->get($url, ['Accept' => 'image/png'])->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame(400, getimagesizefromstring($response->getContent())[0]);
        $this->assertSame(120, getimagesizefromstring($response->getContent())[1]);
        $this->assertSame($webp, Storage::disk('private')->get($attachment->path));
        $this->assertNotSame(hash('sha256', $webp), hash('sha256', $response->getContent()));
    }
}
