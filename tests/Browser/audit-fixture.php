<?php

use App\Models\AuditLog;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Models\StudentToken;
use App\Services\StudentTokenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// Isolated local browser fixtures only. Never execute against production data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures are only allowed in local/testing environments.');
}
$output = getenv('ACADEMY_AUDIT_FIXTURE') ?: '/tmp/academy-audit-fixture.json';
$action = $argv[1] ?? 'seed';
if ($action === 'seed') {
    $video = LessonVideo::where('status', 'ready')->latest('id')->firstOrFail();
    $lesson = $video->lesson;
    $students = [];
    foreach (['assigned', 'stranger', 'expires'] as $role) {
        $student = Student::factory()->create(['name' => 'طالب اختبار '.$role]);
        if ($role !== 'stranger') {
            $student->courses()->attach($lesson->course_id);
        }
        $student->forceFill(['content_agreement_accepted_at' => now(), 'content_agreement_version' => config('audit.agreement_version')])->save();
        $issued = app(StudentTokenService::class)->issue($student);
        $students[$role] = ['id' => $student->id, 'token_id' => $issued['record']->id, 'token' => $issued['token']];
    }
    $image = imagecreatetruecolor(800, 1000);
    imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
    imageellipse($image, 400, 450, 300, 500, imagecolorallocate($image, 40, 40, 40));
    ob_start();
    imagepng($image);
    $imageBytes = ob_get_clean();
    imagedestroy($image);
    $imagePath = 'lessons/audit-'.$lesson->id.'.png';
    Storage::disk('private')->put($imagePath, $imageBytes);
    $imageAttachment = $lesson->attachments()->create(['disk' => 'private', 'path' => $imagePath, 'original_name' => 'audit-image.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($imageBytes)]);
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $content = 'BT /F1 24 Tf 60 700 Td (Dental anatomy audit) Tj ET';
    $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    $pdfPath = 'lessons/audit-'.$lesson->id.'.pdf';
    Storage::disk('private')->put($pdfPath, $pdf);
    $pdfAttachment = $lesson->attachments()->create(['disk' => 'private', 'path' => $pdfPath, 'original_name' => 'audit-notes.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf)]);
    file_put_contents($output, json_encode(['lesson_id' => $lesson->id, 'video_id' => $video->id, 'course_slug' => $lesson->course->slug, 'students' => $students, 'image_attachment_id' => $imageAttachment->id, 'pdf_attachment_id' => $pdfAttachment->id]));
    chmod($output, 0600);
    echo "Local student fixtures prepared.\n";
} elseif ($action === 'expired-url') {
    $data = json_decode(file_get_contents($output), true);
    echo URL::temporarySignedRoute('student.video.asset', now()->subMinute(), ['video' => $data['video_id'], 'student' => $data['students']['assigned']['id'], 'asset' => 'hls/master.m3u8', 'view' => $argv[2]]);
} elseif ($action === 'expire') {
    $data = json_decode(file_get_contents($output), true);
    StudentToken::whereKey($data['students']['expires']['token_id'])->update(['expires_at' => now()->subMinute()]);
    echo "Local test session expired.\n";
} elseif ($action === 'audit') {
    $data = json_decode(file_get_contents($output), true);
    echo json_encode(AuditLog::where('student_id', $data['students']['assigned']['id'])->get(['event', 'points'])->toArray());
}
