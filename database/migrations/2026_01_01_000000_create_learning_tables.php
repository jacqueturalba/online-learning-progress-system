<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('email')->unique(); $table->timestamp('email_verified_at')->nullable(); $table->string('password'); $table->enum('role', ['admin', 'student'])->default('student')->index(); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) { $table->string('email')->primary(); $table->string('token'); $table->timestamp('created_at')->nullable(); });
        Schema::create('sessions', function (Blueprint $table) { $table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index(); $table->string('ip_address', 45)->nullable(); $table->text('user_agent')->nullable(); $table->longText('payload'); $table->integer('last_activity')->index(); });
        Schema::create('categories', function (Blueprint $table) { $table->id(); $table->string('name', 120)->unique(); $table->text('description')->nullable(); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_active')->default(true); $table->timestamps(); $table->index(['is_active', 'sort_order']); });
        Schema::create('lessons', function (Blueprint $table) { $table->id(); $table->foreignId('category_id')->constrained()->cascadeOnDelete(); $table->string('title', 180); $table->text('description')->nullable(); $table->string('video_url', 2048)->nullable(); $table->unsignedInteger('duration_seconds')->nullable(); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_published')->default(false); $table->timestamps(); $table->index(['category_id', 'is_published', 'sort_order']); });
        Schema::create('student_lesson_progress', function (Blueprint $table) { $table->id(); $table->foreignId('student_id')->constrained('users')->cascadeOnDelete(); $table->foreignId('lesson_id')->constrained()->cascadeOnDelete(); $table->unsignedInteger('last_position_seconds')->default(0); $table->decimal('progress_percentage', 5, 2)->default(0); $table->timestamp('started_at')->nullable(); $table->timestamp('last_watched_at')->nullable()->index(); $table->boolean('is_completed')->default(false)->index(); $table->timestamp('completed_at')->nullable(); $table->timestamps(); $table->unique(['student_id', 'lesson_id']); $table->index(['lesson_id', 'is_completed']); });
        Schema::create('student_category_progress', function (Blueprint $table) { $table->id(); $table->foreignId('student_id')->constrained('users')->cascadeOnDelete(); $table->foreignId('category_id')->constrained()->cascadeOnDelete(); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps(); $table->unique(['student_id', 'category_id']); });
        Schema::create('zoom_sessions', function (Blueprint $table) { $table->id(); $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete(); $table->string('title', 180); $table->string('meeting_url', 2048); $table->timestamp('scheduled_at')->index(); $table->text('description')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->index(['is_active', 'scheduled_at']); });
    }
    public function down(): void { Schema::dropIfExists('zoom_sessions'); Schema::dropIfExists('student_category_progress'); Schema::dropIfExists('student_lesson_progress'); Schema::dropIfExists('lessons'); Schema::dropIfExists('categories'); Schema::dropIfExists('sessions'); Schema::dropIfExists('password_reset_tokens'); Schema::dropIfExists('users'); }
};
