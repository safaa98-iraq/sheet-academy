<?php

namespace App\Services;

use App\Models\LessonAttachment;
use App\Models\Student;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class StudentDocumentPageService
{
    public function watermarkedPage(LessonAttachment $attachment, int $page, Student $student): string
    {
        $path = $this->pagePath($attachment, $page);
        $source = Storage::disk('private')->path($path);
        $dimensions = @getimagesize($source);
        abort_unless($dimensions !== false && $dimensions[0] * $dimensions[1] <= 25_000_000, 422, 'حجم أبعاد الصورة غير مدعوم.');
        $image = @imagecreatefromstring(Storage::disk('private')->get($path));
        abort_unless($image !== false, 422, 'تعذّر قراءة الصورة.');
        $scale = min(1, 1600 / imagesx($image), 1600 / imagesy($image));
        $contentWidth = max(1, (int) round(imagesx($image) * $scale));
        $width = max(400, $contentWidth);
        $scaledHeight = max(1, (int) round(imagesy($image) * $scale));
        $height = max(120, $scaledHeight);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, (int) (($width - $contentWidth) / 2), 0, 0, 0, $contentWidth, $scaledHeight, imagesx($image), imagesy($image));
        $identifier = strtoupper(substr(hash_hmac('sha256', 'watermark:'.$student->id, (string) config('app.key')), 0, 16));
        $text = '#'.$student->id.' / '.$identifier;
        $ink = imagecolorallocatealpha($canvas, 80, 80, 80, 58);
        foreach ([0.25, 0.55, 0.85] as $fraction) {
            imagestring($canvas, 5, (int) ($width * 0.08), max(0, (int) ($height * $fraction) - 8), $text, $ink);
        }
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);

        return $bytes;
    }

    /** @return array<int, array{number: int, url: string}> */
    public function pages(LessonAttachment $attachment, string $viewLink): array
    {
        abort_unless(in_array($attachment->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $expiresAt = now()->addMinutes(5);
        $pageCount = $attachment->mime_type === 'application/pdf' ? $this->renderPdf($attachment) : 1;

        return collect(range(1, $pageCount))->map(fn (int $number): array => [
            'number' => $number,
            'url' => URL::temporarySignedRoute('student.documents.page', $expiresAt, ['attachment' => $attachment->id, 'page' => $number, 'view' => $viewLink]),
        ])->all();
    }

    public function pagePath(LessonAttachment $attachment, int $page): string
    {
        abort_unless($page >= 1, 404);
        if ($attachment->mime_type !== 'application/pdf') {
            abort_unless($page === 1, 404);

            return $attachment->path;
        }

        $directory = $this->renderDirectory($attachment);
        $path = $directory.'/page-'.str_pad((string) $page, 2, '0', STR_PAD_LEFT).'.png';
        abort_unless(Storage::disk('private')->exists($path), 404);

        return $path;
    }

    /** @return array<int, string> */
    public function expiredDirectories(int $olderThanHours = 24): array
    {
        $disk = Storage::disk('private');

        return collect($disk->allDirectories('viewer-pages'))->filter(function (string $directory) use ($disk, $olderThanHours): bool {
            $files = $disk->allFiles($directory);
            if ($files === []) {
                return true;
            }

            return max(array_map(fn (string $file): int => $disk->lastModified($file), $files)) < now()->subHours($olderThanHours)->timestamp;
        })->values()->all();
    }

    private function renderPdf(LessonAttachment $attachment): int
    {
        $directory = $this->renderDirectory($attachment);
        $disk = Storage::disk('private');
        if ($disk->exists($directory.'/page-01.png')) {
            return count($disk->files($directory));
        }

        $source = $disk->path($attachment->path);
        $info = Process::timeout(20)->run([(string) config('documents.pdfinfo', 'pdfinfo'), $source]);
        abort_unless($info->successful(), 422, 'تعذّر قراءة ملف PDF.');
        preg_match('/^Pages:\s+(\d+)\s*$/m', $info->output(), $matches);
        $pageCount = (int) ($matches[1] ?? 0);
        abort_if($pageCount < 1 || $pageCount > (int) config('documents.max_pages', 60), 422, 'الملزمة فارغة أو تتجاوز الحد المسموح لعدد الصفحات.');

        $directoryPath = $disk->path($directory);
        if (! is_dir($directoryPath) && ! mkdir($directoryPath, 0700, true) && ! is_dir($directoryPath)) {
            throw new RuntimeException('تعذّر تجهيز مساحة تحويل الملزمة.');
        }
        $prefix = $directoryPath.'/page';
        $render = Process::timeout(120)->run([
            (string) config('documents.pdftoppm', 'pdftoppm'), '-f', '1', '-l', (string) $pageCount,
            '-scale-to', '1600', '-png', $source, $prefix,
        ]);
        if (! $render->successful()) {
            $disk->deleteDirectory($directory);
            abort(422, 'تعذّر تحويل صفحات الملزمة.');
        }
        foreach (glob($prefix.'-*.png') ?: [] as $file) {
            $pageNumber = (int) substr($file, strrpos($file, '-') + 1, -4);
            $target = $directoryPath.'/page-'.str_pad((string) $pageNumber, 2, '0', STR_PAD_LEFT).'.png';
            if ($file !== $target) {
                rename($file, $target);
            }
        }
        abort_unless(count($disk->files($directory)) === $pageCount, 422, 'لم تكتمل معالجة صفحات الملزمة.');

        return $pageCount;
    }

    private function renderDirectory(LessonAttachment $attachment): string
    {
        $version = hash('sha256', $attachment->path.'|'.$attachment->size_bytes.'|'.$attachment->updated_at?->getTimestamp());

        return 'viewer-pages/'.$attachment->id.'/'.$version;
    }
}
