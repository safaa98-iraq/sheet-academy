<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->unsignedTinyInteger('device_limit')->default(1)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->dropColumn('device_limit');
        });
    }
};
