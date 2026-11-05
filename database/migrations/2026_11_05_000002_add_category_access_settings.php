<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'enable_first_lesson_access')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('enable_first_lesson_access')->default(false);
            });
        }
        if (!Schema::hasColumn('categories', 'unlock_all_lessons')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('unlock_all_lessons')->default(false);
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(['enable_first_lesson_access', 'unlock_all_lessons'], fn (string $column): bool => Schema::hasColumn('categories', $column)));
        if ($columns) Schema::table('categories', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
