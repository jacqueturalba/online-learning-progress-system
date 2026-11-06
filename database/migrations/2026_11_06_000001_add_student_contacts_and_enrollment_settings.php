<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone_country_code', 8)->nullable();
            $table->string('phone_number', 32)->nullable();
        });
        Schema::create('enrollment_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('lesson_number')->default(0);
            $table->timestamps();
        });
        Schema::create('student_enrollment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('lesson_number')->default(0);
            $table->timestamps();
        });
        Schema::create('student_enrollment_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->json('data')->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('picture_path')->nullable();
            $table->boolean('privacy_acknowledged')->default(false);
            $table->timestamp('privacy_acknowledged_at')->nullable();
            $table->string('privacy_version', 32)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'revision']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollment_forms');
        Schema::dropIfExists('student_enrollment_settings');
        Schema::dropIfExists('enrollment_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['country', 'address', 'phone_country_code', 'phone_number']));
    }
};
