<?php

namespace App\Services;

use App\Models\LessonAttachment;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class StudentDocumentPageService
{
    public function watermarkedPage(LessonAttachment $attachment, int $page, Student $student, bool $compressed = false): string
    {
        $path = $this->pagePath($attachment, $page);
        $source = Storage::disk('private')->path($path);
        $dimensions = @getimagesize($source);
        abort_unless($dimensions !== false && $dimensions[0] * $dimensions[1] <= 25_000_000, 422, 'حجم أبعاد الصورة غير مدعوم.');
        if ($attachment->mime_type === 'image/webp') {
            $decoded = Process::timeout(20)->run([
                'ffmpeg', '-nostdin', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $source,
                '-vf', "scale=w='min(1600,iw)':h='min(1600,ih)':force_original_aspect_ratio=decrease",
                '-frames:v', '1', '-f', 'image2pipe', '-c:v', 'png', 'pipe:1',
            ]);
            abort_unless($decoded->successful(), 422, 'تعذّر قراءة صورة WebP.');
            $image = @imagecreatefromstring($decoded->output());
        } else {
            $image = @imagecreatefromstring(Storage::disk('private')->get($path));
        }
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
        if ($compressed) {
            ob_start();
            imagejpeg($canvas, null, 88);
            $jpegBytes = (string) ob_get_clean();
            if (strlen($jpegBytes) < strlen($bytes)) {
                $bytes = $jpegBytes;
            }
        }
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
        $pageCount = $attachment->mime_type === 'application/pdf' ? $this->pdfPageCount($attachment) : 1;

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

        abort_if($page > $this->pdfPageCount($attachment), 404);
        $this->renderPdfPage($attachment, $page);
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

    private function pdfPageCount(LessonAttachment $attachment): int
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);
        $pageCount = Cache::remember('document-page-count:'.$this->renderDirectory($attachment), now()->addHours(24), function () use ($attachment, $disk): int {
            $info = Process::timeout(20)->run([(string) config('documents.pdfinfo', 'pdfinfo'), $disk->path($attachment->path)]);
            if (! $info->successful()) {
                Log::warning('document_metadata_failed', ['attachment_id' => $attachment->id, 'exit_code' => $info->exitCode()]);
                abort(422, 'تعذّر قراءة ملف PDF. قد يكون تالفاً أو محمياً بكلمة مرور.');
            }
            preg_match('/^Pages:\s+(\d+)\s*$/m', $info->output(), $matches);

            return (int) ($matches[1] ?? 0);
        });
        abort_if($pageCount < 1 || $pageCount > (int) config('documents.max_pages', 500), 422, 'الملزمة فارغة أو تتجاوز الحد المسموح لعدد الصفحات.');

        return $pageCount;
    }

    private function renderPdfPage(LessonAttachment $attachment, int $page): void
    {
        $disk = Storage::disk('private');
        $directory = $this->renderDirectory($attachment);
        $target = $directory.'/page-'.str_pad((string) $page, 2, '0', STR_PAD_LEFT).'.png';
        if ($disk->exists($target)) {
            return;
        }
        Cache::lock('document-page-render:'.$directory.':'.$page, 90)->block(10, function () use ($attachment, $page, $disk, $directory, $target): void {
            if ($disk->exists($target)) {
                return;
            }
            $directoryPath = $disk->path($directory);
            if (! is_dir($directoryPath) && ! mkdir($directoryPath, 0700, true) && ! is_dir($directoryPath)) {
                throw new RuntimeException('تعذّر تجهيز مساحة تحويل الملزمة.');
            }
            $prefix = $directoryPath.'/.render-'.$page.'-'.bin2hex(random_bytes(8));
            $temporary = $prefix.'.png';
            try {
                $render = Process::timeout(60)->run([
                    (string) config('documents.pdftoppm', 'pdftoppm'), '-f', (string) $page, '-l', (string) $page,
                    '-singlefile', '-scale-to', '1600', '-png', Storage::disk($attachment->disk)->path($attachment->path), $prefix,
                ]);
                if (! $render->successful() || ! is_file($temporary) || @getimagesize($temporary) === false) {
                    Log::warning('document_render_failed', ['attachment_id' => $attachment->id, 'page' => $page, 'exit_code' => $render->exitCode()]);
                    abort(422, 'تعذّر تجهيز هذه الصفحة. حاول فتحها مجدداً.');
                }
                if (! rename($temporary, $disk->path($target))) {
                    throw new RuntimeException('تعذّر حفظ صفحة الملزمة.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });
    }

    private function renderDirectory(LessonAttachment $attachment): string
    {
        $version = hash('sha256', $attachment->path.'|'.$attachment->size_bytes.'|'.$attachment->updated_at?->getTimestamp());

        return 'viewer-pages/'.$attachment->id.'/'.$version;
    }
}
