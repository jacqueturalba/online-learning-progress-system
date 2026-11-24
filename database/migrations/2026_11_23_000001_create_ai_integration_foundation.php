<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80)->index();
            $table->string('label', 120);
            $table->string('endpoint', 2048)->nullable();
            $table->text('credentials');
            $table->boolean('is_enabled')->default(false)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['provider', 'label']);
        });

        Schema::create('ai_service_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 40)->unique();
            $table->foreignId('ai_integration_id')->constrained('ai_integrations')->restrictOnDelete();
            $table->string('model', 160);
            $table->string('target_language', 35)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_integration_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_integration_id')->nullable()->constrained('ai_integrations')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ai_operation_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_integration_id')->nullable()->constrained('ai_integrations')->nullOnDelete();
            $table->string('service', 40)->index();
            $table->string('model', 160);
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('status', 30)->index();
            $table->unsignedInteger('processing_duration_ms')->nullable();
            $table->decimal('audio_minutes', 12, 4)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost', 14, 8)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('request_reference', 180)->nullable()->index();
            $table->timestamps();
            $table->index(['ai_integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operation_usages');
        Schema::dropIfExists('ai_integration_audits');
        Schema::dropIfExists('ai_service_assignments');
        Schema::dropIfExists('ai_integrations');
    }
};
