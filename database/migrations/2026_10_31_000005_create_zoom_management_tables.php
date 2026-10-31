<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zoom_integration_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('account_id')->nullable();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('student_zoom_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('zoom_url', 2048);
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_zoom_assignments');
        Schema::dropIfExists('zoom_integration_settings');
    }
};
