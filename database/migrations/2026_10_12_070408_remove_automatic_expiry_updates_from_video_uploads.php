<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_uploads', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });

        $restored = 0;
        DB::table('video_uploads')->where('status', 'uploading')
            ->whereColumn('expires_at', '<=', 'updated_at')
            ->where('updated_at', '>=', now()->subDays(7))
            ->orderBy('id')->chunkById(100, function ($uploads) use (&$restored): void {
                foreach ($uploads as $upload) {
                    if (Storage::disk('private')->exists($upload->path)) {
                        DB::table('video_uploads')->where('id', $upload->id)->update(['expires_at' => now()->addDays(7)]);
                        $restored++;
                    }
                }
            });
        Log::info('video_upload.expiry_schema_corrected', ['restored_partial_sessions' => $restored]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_uploads', function (Blueprint $table) {
            $table->timestamp('expires_at')->useCurrentOnUpdate(false)->change();
        });
    }
};
