<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_lesson_progress', function (Blueprint $table) {
            $table->unsignedInteger('watched_seconds')->default(0)->after('last_position_seconds');
            $table->boolean('is_playing')->default(false)->after('last_watched_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_lesson_progress', function (Blueprint $table) {
            $table->dropColumn(['watched_seconds', 'is_playing']);
        });
    }
};
