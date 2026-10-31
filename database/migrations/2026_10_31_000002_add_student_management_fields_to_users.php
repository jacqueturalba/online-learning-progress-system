<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pseudocode', 14)->nullable()->after('email');
            $table->string('pace_group', 20)->default('standard')->after('role');
            $table->boolean('is_active')->default(true)->index()->after('pace_group');
        });

        DB::table('users')->where('role', 'student')->whereNull('pseudocode')->orderBy('id')->chunkById(100, function ($students): void {
            foreach ($students as $student) {
                do {
                    $pseudocode = 'STU-'.Str::upper(Str::random(10));
                } while (DB::table('users')->where('pseudocode', $pseudocode)->exists());

                DB::table('users')->where('id', $student->id)->update(['pseudocode' => $pseudocode]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('pseudocode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pseudocode']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['pseudocode', 'pace_group', 'is_active']);
        });
    }
};
