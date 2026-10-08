<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonVideo;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use App\Services\StudentAuditService;
use App\Services\StudentDocumentPageService;
use App\Services\StudentTokenService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinalSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['manifest'])]
    #[TestWith(['key'])]
    #[TestWith(['segment'])]
    public function test_private_media_refuses_missing_expired_unassigned_and_forged_access(string $asset): void
    {
        Storage::fake('private');
        $this->freezeTime();
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'publication_status' => 'published', 'is_published' => true]);
        $video = LessonVideo::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'output_path' => 'hls/'.$lesson->id, 'key_path' => 'video-keys/'.$lesson->id.'.key', 'enabled_resolutions' => [360]]);
        Storage::disk('private')->put($video->key_path, random_bytes(16));
        Storage::disk('private')->put($video->output_path.'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360\nv360/index.m3u8\n");
        Storage::disk('private')->put($video->output_path.'/v360/segment_00000.ts', openssl_encrypt(str_repeat('secret', 100), 'aes-128-cbc', Storage::disk('private')->get($video->key_path), OPENSSL_RAW_DATA, str_repeat("\0", 16)));
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $view = parse_url($this->issueStudentViewLink('lesson', $lesson->id), PHP_URL_QUERY);
        parse_str($view, $query);
        $parameters = ['video' => $video->id, 'student' => $student->id, 'view' => $query['view']];
        $route = $asset === 'key' ? 'student.video.key' : 'student.video.asset';
        if ($asset !== 'key') {
            $parameters['asset'] = $asset === 'manifest' ? 'hls/master.m3u8' : 'hls/v360/segment_00000.ts';
        }
        $url = URL::temporarySignedRoute($route, now()->addMinutes(5), $parameters);
        $this->get($url)->assertOk()->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->get($url)->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->get($url.'&unexpected=1')->assertForbidden();
        $expired = URL::temporarySignedRoute($route, now()->subSecond(), $parameters);
        $this->get($expired)->assertForbidden();
        $this->get(route($route, $parameters))->assertForbidden();
        $this->post(route('student.logout'))->assertRedirect();
        $this->getJson($url)->assertUnauthorized();
        $stranger = Student::factory()->create();
        $this->loginStudent(app(StudentTokenService::class)->issue($stranger)['token']);
        $this->getJson($url)->assertNotFound();
        $this->post(route('student.logout'));
        $this->loginStudent($issued['token']);
        $issued['record']->update(['expires_at' => now()->subSecond()]);
        $this->get($url)->assertRedirect(route('student.login'));
    }

    public function test_tampering_revokes_the_current_view_without_losing_progress_or_preferences(): void
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'publication_status' => 'published', 'is_published' => true]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $student->preference()->create(['video_quality' => '360p']);
        $student->progress()->create(['lesson_id' => $lesson->id, 'last_position_seconds' => 32, 'watched_seconds' => 24]);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson(route('student.activity.store'), ['event' => 'suspicious_watermark', 'lesson_id' => $lesson->id, 'view' => $query['view']])->assertOk();
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'suspicious_watermark', 'points' => 5]);
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 32]);
        $this->assertSame('360p', $student->fresh()->preference->video_quality);
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk();
    }

    public function test_exiting_a_view_invalidates_its_link_immediately(): void
    {
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'publication_status' => 'published', 'is_published' => true]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson(route('student.view-links.close'), ['view' => $query['view']])->assertOk();
        $this->get($url)->assertNotFound();
    }

    public function test_image_bytes_include_a_server_watermark_and_original_is_never_served(): void
    {
        Storage::fake('private');
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'publication_status' => 'published', 'is_published' => true]);
        $image = imagecreatetruecolor(800, 1000);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $original = ob_get_clean();
        imagedestroy($image);
        Storage::disk('private')->put('lessons/page.png', $original);
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'lessons/page.png', 'original_name' => 'page.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($original)]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        parse_str(parse_url($this->issueStudentViewLink('document', $attachment->id), PHP_URL_QUERY), $query);
        $url = URL::temporarySignedRoute('student.documents.page', now()->addMinutes(5), ['attachment' => $attachment->id, 'page' => 1, 'view' => $query['view']]);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNotSame(hash('sha256', $original), hash('sha256', $response->getContent()));
        $rendered = imagecreatefromstring($response->getContent());
        $image = imagecreatefromstring($original);
        $differences = 0;
        for ($y = 240; $y < 260; $y++) {
            for ($x = 64; $x < 400; $x++) {
                if (imagecolorat($image, $x, $y) !== imagecolorat($rendered, $x, $y)) {
                    $differences++;
                }
            }
        }
        $this->assertGreaterThan(20, $differences);
        imagedestroy($rendered);
        imagedestroy($image);
        $this->get('/storage/lessons/page.png')->assertNotFound();
        $this->get('/storage/app/private/lessons/page.png')->assertNotFound();
        $this->post(route('student.logout'));
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_token_is_one_time_and_old_sessions_are_revoked_when_regenerated(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student, deviceLimit: 10);
        $this->assertSame(1, $issued['record']->device_limit);
        $this->loginStudent($issued['token']);
        $device = StudentDevice::firstOrFail();
        $new = app(StudentTokenService::class)->issue($student);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertSame('revoked', $issued['record']->fresh()->status);
        $this->assertNull($new['record']->getRawOriginal('encrypted_token'));
        $this->assertSame(hash('sha256', $new['token']), $new['record']->token_hash);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->postJson(route('admin.students.token.reveal', $student))->assertUnprocessable()->assertDontSee($new['token']);
    }

    public function test_no_public_registration_routes_or_mutations_exist(): void
    {
        foreach (['/register', '/signup', '/api/register', '/student/register'] as $url) {
            $this->get($url)->assertNotFound();
            $this->post($url)->assertNotFound();
        }
    }

    public function test_server_watermark_identifier_is_unique_and_ignores_client_input(): void
    {
        $first = Student::factory()->create(['name' => 'طالب']);
        $second = Student::factory()->create(['name' => 'طالب']);
        $audit = app(StudentAuditService::class);
        $this->assertNotSame($audit->watermarkText($first), $audit->watermarkText($second));
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}/', $audit->watermarkText($first));
    }

    public function test_delegated_admin_cannot_assign_permissions_they_do_not_hold(): void
    {
        $this->seed(PermissionsAndRolesSeeder::class);
        $manager = User::factory()->create();
        $limited = Role::create(['name' => 'limited-admin-manager', 'label' => 'مدير محدود', 'guard_name' => 'web']);
        $limited->permissions()->attach(Permission::where('name', 'admins.manage')->firstOrFail());
        $manager->roles()->attach($limited);
        $target = Role::where('name', 'content-editor')->firstOrFail();
        $this->actingAs($manager, 'web')->postJson(route('admin.admins.store'), [
            'name' => 'تصعيد ممنوع', 'email' => 'escalate@example.test',
            'password' => 'Long-password-123!', 'password_confirmation' => 'Long-password-123!', 'role_ids' => [$target->id],
        ])->assertForbidden();
        $this->postJson(route('admin.roles.store'), ['name' => 'escalated-role', 'label' => 'تصعيد', 'permission_ids' => $target->permissions->modelKeys()])->assertForbidden();
        $this->putJson(route('admin.roles.permissions', $limited), ['permission_ids' => $target->permissions->modelKeys()])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.test']);
        $this->assertSame(['admins.manage'], $limited->fresh()->permissions->pluck('name')->all());
    }

    public function test_csv_export_neutralizes_spreadsheet_formula_injection(): void
    {
        Student::factory()->create(['name' => '=HYPERLINK("https://attacker.invalid")', 'email' => '+formula@example.test']);
        $response = $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->get(route('admin.student-progress.export'))->assertOk();
        $csv = $response->streamedContent();
        $rows = explode("\n", trim($csv));
        $cells = str_getcsv($rows[1], ',', '"', '\\');
        $this->assertStringStartsWith("'=", $cells[0]);
        $this->assertStringStartsWith("'+", $cells[1]);
    }

    public function test_untrusted_forwarded_headers_cannot_spoof_client_identity_or_host(): void
    {
        $student = Student::factory()->create();
        $token = app(StudentTokenService::class)->issue($student)['token'];
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders([
            'X-Forwarded-For' => '198.51.100.23', 'X-Forwarded-Host' => 'attacker.invalid', 'X-Forwarded-Proto' => 'https',
        ])->post(route('student.login.store'), ['token' => $token])->assertRedirect();
        $this->assertSame('127.0.0.1', StudentDevice::firstOrFail()->ip_address);
        $this->get('/login')->assertDontSee('attacker.invalid');
    }

    public function test_real_beacon_form_payload_saves_position_on_page_exit(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create(['duration_seconds' => 180]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->post(route('student.progress.store', $lesson), ['event' => 'unloaded', 'position_seconds' => '42', 'is_playing' => '0'])
            ->assertOk();
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 42, 'watched_seconds' => 0]);
    }

    public function test_playback_rate_limit_cannot_suppress_security_events_and_security_flood_revokes_access(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson(route('student.activity.store'), ['event' => 'watch_heartbeat', 'view' => $query['view']])->assertOk();
        }
        $this->postJson(route('student.activity.store'), ['event' => 'suspicious_watermark', 'view' => $query['view']])->assertOk();
        $url = $this->issueStudentViewLink('lesson', $lesson->id);
        for ($attempt = 0; $attempt < 29; $attempt++) {
            $this->postJson(route('student.activity.store'), ['event' => 'suspicious_copy'])->assertOk();
        }
        $this->postJson(route('student.activity.store'), ['event' => 'suspicious_copy'])->assertStatus(429);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['student_id' => $student->id, 'event' => 'suspicious_activity_flood', 'points' => 5]);
        $this->postJson(route('student.activity.store'), ['event' => 'suspicious_copy'])->assertStatus(429);
        $this->assertSame(1, AuditLog::where('event', 'suspicious_activity_flood')->count());
    }

    public function test_hidden_ancestor_content_does_not_leak_through_course_payloads_and_player_uses_the_real_tree(): void
    {
        $course = Course::factory()->create();
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        $part = $root->children()->create(['course_id' => $course->id, 'type' => 'part', 'title' => 'جزء حقيقي منشور', 'publication_status' => 'published']);
        $chapter = $part->children()->create(['course_id' => $course->id, 'type' => 'chapter', 'title' => 'فصل حقيقي', 'publication_status' => 'published']);
        $section = $chapter->children()->create(['course_id' => $course->id, 'type' => 'section', 'title' => 'قسم حقيقي', 'publication_status' => 'published']);
        $visible = Lesson::factory()->for($course)->create();
        $section->children()->create(['course_id' => $course->id, 'lesson_id' => $visible->id, 'type' => 'lesson', 'title' => $visible->title, 'publication_status' => 'published']);
        $hidden = Lesson::factory()->for($course)->create(['body_html' => '<p>محتوى سري تحت جزء مخفي</p>']);
        $draft = $root->children()->create(['course_id' => $course->id, 'type' => 'part', 'title' => 'جزء مخفي', 'publication_status' => 'draft']);
        $draft->children()->create(['course_id' => $course->id, 'lesson_id' => $hidden->id, 'type' => 'lesson', 'title' => $hidden->title, 'publication_status' => 'published']);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get(route('student.course.show', $course))->assertOk()->assertDontSee($hidden->title)->assertDontSee('محتوى سري تحت جزء مخفي');
        $this->get($this->issueStudentViewLink('lesson', $visible->id))->assertOk()->assertSee('جزء حقيقي منشور')->assertSee('فصل حقيقي')->assertSee('قسم حقيقي')->assertDontSee('محتوى سري تحت جزء مخفي');
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => $hidden->id])->assertNotFound();
    }

    public function test_errors_and_generic_validation_are_arabic_with_security_headers(): void
    {
        $this->getJson('/missing-audit-route')->assertNotFound()->assertJsonPath('message', 'المحتوى غير متاح.')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $student = Student::factory()->create();
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->postJson(route('student.view-links.issue'), ['target_type' => 'lesson', 'target_id' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('errors.target_id.0', 'يجب أن يكون المحتوى عدداً صحيحاً.');
    }

    public function test_extremely_narrow_images_do_not_expand_into_unbounded_watermark_canvases(): void
    {
        Storage::fake('private');
        $image = imagecreatetruecolor(1, 8192);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('private')->put('lessons/narrow.png', $bytes);
        $attachment = LessonAttachment::create(['lesson_id' => Lesson::factory()->create()->id, 'disk' => 'private', 'path' => 'lessons/narrow.png', 'original_name' => 'narrow.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($bytes)]);
        $output = app(StudentDocumentPageService::class)->watermarkedPage($attachment, 1, Student::factory()->create());
        $dimensions = getimagesizefromstring($output);
        $this->assertSame(400, $dimensions[0]);
        $this->assertSame(1600, $dimensions[1]);
    }

    public function test_regenerating_a_frozen_token_never_restores_the_old_token_after_reactivation(): void
    {
        $student = Student::factory()->create();
        $old = app(StudentTokenService::class)->issue($student);
        $student->update(['status' => 'frozen']);
        $old['record']->update(['status' => 'frozen']);
        $new = app(StudentTokenService::class)->issue($student);
        $this->assertSame('revoked', $old['record']->fresh()->status);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->patch(route('admin.students.status', $student), ['status' => 'active'])->assertRedirect();
        $this->post('/login', ['token' => $old['token']])->assertSessionHasErrors('token');
        $this->loginStudent($new['token'])->assertRedirect();
        $this->assertAuthenticatedAs($student, 'student');
    }
}
