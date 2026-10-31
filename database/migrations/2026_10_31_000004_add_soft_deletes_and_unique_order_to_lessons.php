<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->softDeletes()->index();
        });

        $duplicateCategories = DB::table('lessons')
            ->select('category_id')
            ->groupBy('category_id')
            ->havingRaw('COUNT(*) > COUNT(DISTINCT sort_order)')
            ->pluck('category_id');

        foreach ($duplicateCategories as $categoryId) {
            $lessons = DB::table('lessons')->where('category_id', $categoryId)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);
            $temporaryOrder = ((int) $lessons->max('sort_order')) + 1;

            foreach ($lessons as $index => $lesson) {
                DB::table('lessons')->where('id', $lesson->id)->update(['sort_order' => $temporaryOrder + $index]);
            }

            foreach ($lessons as $index => $lesson) {
                DB::table('lessons')->where('id', $lesson->id)->update(['sort_order' => $index + 1]);
            }
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->unique(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropUnique(['category_id', 'sort_order']);
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
