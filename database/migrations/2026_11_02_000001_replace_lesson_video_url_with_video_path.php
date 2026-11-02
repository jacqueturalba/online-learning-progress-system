<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('video_path', 1024)->nullable();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('video_url', 2048)->nullable();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('video_path');
        });
    }
};
