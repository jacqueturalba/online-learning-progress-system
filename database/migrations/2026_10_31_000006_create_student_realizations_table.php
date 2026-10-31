<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_realizations', function (Blueprint $table): void {
            $table->id();
            // Restrict physical deletion so a student's submitted learning history is retained.
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->longText('realization');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();
            $table->index(['student_id', 'submitted_at']);
            $table->index(['lesson_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_realizations');
    }
};
