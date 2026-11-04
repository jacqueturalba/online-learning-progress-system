<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoom_attendance_records', function (Blueprint $table): void {
            $table->string('matching_method', 32)->nullable()->after('match_status');
        });
    }

    public function down(): void
    {
        Schema::table('zoom_attendance_records', function (Blueprint $table): void {
            $table->dropColumn('matching_method');
        });
    }
};
