<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('student_zoom_assignments', 'registration_error')) {
            Schema::table('student_zoom_assignments', function (Blueprint $table): void {
                $table->text('registration_error')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('student_zoom_assignments', function (Blueprint $table): void {
            $table->dropColumn('registration_error');
        });
    }
};
