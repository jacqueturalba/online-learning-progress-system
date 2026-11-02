<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_category_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('to_category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->useCurrent();
            $table->timestamps();
            $table->unique(['student_id', 'from_category_id']);
            $table->index(['from_category_id', 'transferred_at']);
            $table->index(['to_category_id', 'transferred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_category_transfers');
    }
};
