<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('student_tokens')->update(['device_limit' => 1]);
            DB::table('student_devices')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'view_link_hash' => null]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revoked sessions must not be restored by a rollback.
    }
};
