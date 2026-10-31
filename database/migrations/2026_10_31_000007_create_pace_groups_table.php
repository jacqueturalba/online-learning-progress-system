<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pace_groups', function (Blueprint $table): void {
            $table->string('code', 20)->primary();
            $table->unsignedSmallInteger('weekly_target')->default(0);
            $table->timestamps();
        });

        DB::table('pace_groups')->insert([
            ['code' => 'A', 'weekly_target' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'B', 'weekly_target' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C', 'weekly_target' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'D', 'weekly_target' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Preserve existing assignments while replacing the old descriptive groups.
        DB::table('users')->where('pace_group', 'fast')->update(['pace_group' => 'A']);
        DB::table('users')->where('pace_group', 'standard')->update(['pace_group' => 'B']);
        DB::table('users')->where('pace_group', 'slow')->update(['pace_group' => 'C']);
        DB::table('users')->whereNotIn('pace_group', ['A', 'B', 'C', 'D'])->update(['pace_group' => 'B']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('pace_group', 20)->default('B')->change();
            $table->foreign('pace_group')->references('code')->on('pace_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['pace_group']);
            $table->string('pace_group', 20)->default('standard')->change();
        });

        DB::table('users')->where('pace_group', 'A')->update(['pace_group' => 'fast']);
        DB::table('users')->where('pace_group', 'B')->update(['pace_group' => 'standard']);
        DB::table('users')->whereIn('pace_group', ['C', 'D'])->update(['pace_group' => 'slow']);
        Schema::dropIfExists('pace_groups');
    }
};
