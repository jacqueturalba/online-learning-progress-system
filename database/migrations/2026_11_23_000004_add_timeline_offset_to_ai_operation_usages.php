<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_operation_usages', function (Blueprint $table): void {
            $table->decimal('timeline_offset_seconds', 12, 3)->nullable()->after('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_operation_usages', function (Blueprint $table): void {
            $table->dropColumn('timeline_offset_seconds');
        });
    }
};
