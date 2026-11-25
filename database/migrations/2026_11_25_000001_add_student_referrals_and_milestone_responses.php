<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('who_invited', 191)->nullable();
        });

        Schema::table('student_realizations', function (Blueprint $table): void {
            $table->text('questions')->nullable();
        });

        Schema::create('student_milestone_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->unsignedBigInteger('zoom_session_id')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('milestone_key', 64);
            $table->text('response');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['student_id', 'milestone_key']);
            $table->index(['lesson_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_milestone_responses');
        Schema::table('student_realizations', fn (Blueprint $table) => $table->dropColumn('questions'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('who_invited'));
    }
};
