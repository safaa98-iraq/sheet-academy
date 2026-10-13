<?php

namespace App\Console\Commands;

use App\Models\LessonAttachment;
use App\Services\StudentDocumentPageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

#[Signature('app:diagnose-document {attachment : Attachment ID} {--render-page= : Render one private page for verification}')]
#[Description('Inspect private document metadata without exposing its source or student details.')]
class DiagnoseDocument extends Command
{
    public function handle(StudentDocumentPageService $pages): int
    {
        $attachment = LessonAttachment::find((int) $this->argument('attachment'));
        $result = ['id' => (int) $this->argument('attachment'), 'found' => $attachment !== null];
        if ($attachment !== null) {
            $disk = Storage::disk($attachment->disk);
            $result += ['mime' => $attachment->mime_type, 'bytes' => $attachment->size_bytes, 'exists' => $disk->exists($attachment->path)];
            if ($result['exists'] && $attachment->mime_type === 'application/pdf') {
                $info = Process::timeout(20)->run([(string) config('documents.pdfinfo', 'pdfinfo'), $disk->path($attachment->path)]);
                preg_match('/^Pages:\s+(\d+)\s*$/m', $info->output(), $matches);
                $result += ['pdfinfo_exit' => $info->exitCode(), 'pages' => (int) ($matches[1] ?? 0), 'max_pages' => config('documents.max_pages')];
                if ($this->option('render-page') !== null) {
                    $page = (int) $this->option('render-page');
                    $path = $pages->pagePath($attachment, $page);
                    $dimensions = getimagesize(Storage::disk('private')->path($path));
                    $result += ['rendered_page' => $page, 'width' => $dimensions[0], 'height' => $dimensions[1]];
                }
            }
        }
        $this->line('DOCUMENT_DIAG '.json_encode($result));

        return self::SUCCESS;
    }
}
