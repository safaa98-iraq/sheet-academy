<?php

namespace App\Console\Commands;

use App\Services\StudentDocumentPageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('app:prune-student-document-pages')]
#[Description('Remove expired private PDF page renders.')]
class PruneStudentDocumentPages extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StudentDocumentPageService $pages): int
    {
        $directories = $pages->expiredDirectories((int) config('documents.temporary_hours', 24));
        foreach ($directories as $directory) {
            Storage::disk('private')->deleteDirectory($directory);
        }

        $this->info('Removed '.count($directories).' expired document render directories.');

        return self::SUCCESS;
    }
}
