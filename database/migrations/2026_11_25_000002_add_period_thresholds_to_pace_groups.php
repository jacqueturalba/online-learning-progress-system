<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pace_groups', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_completed_lessons')->nullable()->after('weekly_target');
            $table->string('measurement_period', 12)->default('weekly')->after('minimum_completed_lessons');
        });

        foreach (['A', 'B', 'C', 'D'] as $code) {
            $target = (int) DB::table('pace_groups')->where('code', $code)->value('weekly_target');
            DB::table('pace_groups')->where('code', $code)->update([
                'minimum_completed_lessons' => $target > 0 ? $target : null,
                'measurement_period' => 'weekly',
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('pace_group', 20)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNull('pace_group')->update(['pace_group' => 'B']);
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pace_group', 20)->default('B')->nullable(false)->change();
        });
        Schema::table('pace_groups', function (Blueprint $table): void {
            $table->dropColumn(['minimum_completed_lessons', 'measurement_period']);
        });
    }
};
