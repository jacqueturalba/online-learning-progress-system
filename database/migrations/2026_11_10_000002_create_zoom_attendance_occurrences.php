<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('zoom_attendance_settings')) {
            Schema::create('zoom_attendance_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedTinyInteger('present_threshold')->default(80);
                $table->unsignedTinyInteger('late_threshold')->default(45);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zoom_attendance_occurrences')) {
            Schema::create('zoom_attendance_occurrences', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('zoom_session_id');
                $table->string('meeting_uuid', 191);
                $table->timestamp('started_at')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->unsignedInteger('participants_detected')->default(0);
                $table->timestamp('synced_at')->nullable();
                $table->string('sync_status', 24)->default('pending');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zoom_attendance_occurrence_students')) {
            Schema::create('zoom_attendance_occurrence_students', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('occurrence_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->string('attendance_code', 16)->default('X');
                $table->boolean('is_manual')->default(false);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('manually_updated_at')->nullable();
                $table->timestamps();
            });
        }

        // Add indexes separately so a failure can be retried after MySQL has created
        // the tables. Check by indexed columns so existing automatically named keys
        // are recognized and are not duplicated.
        $this->ensureIndex('zoom_attendance_occurrences', ['zoom_session_id', 'meeting_uuid'], 'unique', 'zao_session_uuid_uq');
        $this->ensureIndex('zoom_attendance_occurrences', ['zoom_session_id', 'started_at'], null, 'zao_session_started_idx');
        $this->ensureForeignKey('zoom_attendance_occurrences', 'zoom_session_id', 'zoom_sessions', 'id', 'zao_session_fk', 'restrict');

        $this->ensureIndex('zoom_attendance_occurrence_students', ['occurrence_id', 'student_id'], 'unique', 'zaos_occ_student_uq');
        $this->ensureIndex('zoom_attendance_occurrence_students', ['student_id', 'occurrence_id'], null, 'zaos_student_occurrence_idx');
        $this->ensureForeignKey('zoom_attendance_occurrence_students', 'occurrence_id', 'zoom_attendance_occurrences', 'id', 'zaos_occurrence_fk', 'cascade');
        $this->ensureForeignKey('zoom_attendance_occurrence_students', 'student_id', 'users', 'id', 'zaos_student_fk', 'cascade');
        $this->ensureForeignKey('zoom_attendance_occurrence_students', 'updated_by', 'users', 'id', 'zaos_updated_by_fk', 'null');
    }

    private function ensureIndex(string $tableName, array $columns, ?string $type, string $name): void
    {
        if (Schema::hasIndex($tableName, $columns, $type)) return;

        Schema::table($tableName, function (Blueprint $table) use ($columns, $type, $name): void {
            if ($type === 'unique') $table->unique($columns, $name);
            else $table->index($columns, $name);
        });
    }

    private function ensureForeignKey(string $tableName, string $column, string $referencedTable, string $referencedColumn, string $name, string $deleteAction): void
    {
        if (Schema::hasForeignKey($tableName, [$column])) return;

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $referencedColumn, $name, $deleteAction): void {
            $foreign = $table->foreign($column, $name)->references($referencedColumn)->on($referencedTable);
            if ($deleteAction === 'cascade') $foreign->cascadeOnDelete();
            elseif ($deleteAction === 'null') $foreign->nullOnDelete();
            else $foreign->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_attendance_occurrence_students');
        Schema::dropIfExists('zoom_attendance_occurrences');
        Schema::dropIfExists('zoom_attendance_settings');
    }
};
