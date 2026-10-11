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
            $table->dropColumn('encrypted_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_tokens', function (Blueprint $table): void {
            $table->text('encrypted_token')->nullable();
        });
    }
};
