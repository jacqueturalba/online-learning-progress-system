<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('zoom_batches')) {
            Schema::create('zoom_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('capacity')->default(30);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'starts_at']);
            });
        }

        if (! Schema::hasTable('zoom_batch_memberships')) {
            Schema::create('zoom_batch_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('zoom_batch_id')->constrained('zoom_batches')->restrictOnDelete();
                $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
                $table->string('status', 20)->default('active');
                $table->timestamp('joined_at');
                $table->timestamp('ended_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['student_id', 'status']);
                $table->index(['zoom_batch_id', 'status']);

                if (DB::connection()->getDriverName() === 'mysql') {
                    $table->unsignedBigInteger('active_student_id')->nullable()
                        ->storedAs("CASE WHEN status = 'active' THEN student_id ELSE NULL END");
                    $table->unique(['zoom_batch_id', 'active_student_id'], 'zoom_batch_memberships_one_active_student');
                }
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite' && ! Schema::hasIndex('zoom_batch_memberships', 'zoom_batch_memberships_one_active_student')) {
            DB::statement("CREATE UNIQUE INDEX zoom_batch_memberships_one_active_student ON zoom_batch_memberships (zoom_batch_id, student_id) WHERE status = 'active'");
        }

        $sessionColumns = Schema::getColumnListing('zoom_sessions');
        $addSessionBatchColumn = ! in_array('zoom_batch_id', $sessionColumns, true);
        $addSessionMeetingIdColumn = ! in_array('zoom_meeting_id', $sessionColumns, true);
        $addSessionMeetingUuidColumn = ! in_array('zoom_meeting_uuid', $sessionColumns, true);
        $addSessionHostColumn = ! in_array('zoom_host_id', $sessionColumns, true);
        $addSessionMeetingTypeColumn = ! in_array('zoom_meeting_type', $sessionColumns, true);
        $addSessionRegistrationTypeColumn = ! in_array('registration_type', $sessionColumns, true);
        $addSessionRecurrenceColumn = ! in_array('recurrence_config', $sessionColumns, true);
        $addSessionRegistrationUrlColumn = ! in_array('registration_url', $sessionColumns, true);
        $addSessionStatusColumn = ! in_array('meeting_status', $sessionColumns, true);
        $addSessionSyncedAtColumn = ! in_array('last_synced_at', $sessionColumns, true);
        $addSessionDeletedAtColumn = ! in_array('deleted_at', $sessionColumns, true);
        $addSessionBatchForeign = $addSessionBatchColumn || ! Schema::hasForeignKey('zoom_sessions', 'zoom_sessions_zoom_batch_id_foreign');
        $addSessionScheduleIndex = ! Schema::hasIndex('zoom_sessions', 'zoom_sessions_batch_schedule_index');
        $addZoomMeetingUnique = ! Schema::hasIndex('zoom_sessions', 'zoom_sessions_zoom_meeting_id_unique');

        Schema::table('zoom_sessions', function (Blueprint $table) use (
            $addSessionBatchColumn,
            $addSessionMeetingIdColumn,
            $addSessionMeetingUuidColumn,
            $addSessionHostColumn,
            $addSessionMeetingTypeColumn,
            $addSessionRegistrationTypeColumn,
            $addSessionRecurrenceColumn,
            $addSessionRegistrationUrlColumn,
            $addSessionStatusColumn,
            $addSessionSyncedAtColumn,
            $addSessionDeletedAtColumn,
            $addSessionBatchForeign,
            $addSessionScheduleIndex,
            $addZoomMeetingUnique,
        ): void {
            if ($addSessionBatchColumn) $table->foreignId('zoom_batch_id')->nullable()->after('id');
            if ($addSessionMeetingIdColumn) $table->string('zoom_meeting_id', 64)->nullable();
            if ($addSessionMeetingUuidColumn) $table->string('zoom_meeting_uuid', 191)->nullable();
            if ($addSessionHostColumn) $table->string('zoom_host_id', 191)->nullable();
            if ($addSessionMeetingTypeColumn) $table->unsignedTinyInteger('zoom_meeting_type')->nullable();
            if ($addSessionRegistrationTypeColumn) $table->unsignedTinyInteger('registration_type')->nullable();
            if ($addSessionRecurrenceColumn) $table->json('recurrence_config')->nullable();
            if ($addSessionRegistrationUrlColumn) $table->text('registration_url')->nullable();
            if ($addSessionStatusColumn) $table->string('meeting_status', 20)->nullable();
            if ($addSessionSyncedAtColumn) $table->timestamp('last_synced_at')->nullable();
            if ($addSessionDeletedAtColumn) $table->softDeletes();
            if ($addSessionBatchForeign) $table->foreign('zoom_batch_id')->references('id')->on('zoom_batches')->restrictOnDelete();
            if ($addSessionScheduleIndex) $table->index(['zoom_batch_id', 'scheduled_at'], 'zoom_sessions_batch_schedule_index');
            if ($addZoomMeetingUnique) $table->unique('zoom_meeting_id');
        });

        $hasStudentUnique = Schema::hasIndex('student_zoom_assignments', 'student_zoom_assignments_student_id_unique');
        $hasStudentForeign = Schema::hasForeignKey('student_zoom_assignments', 'student_zoom_assignments_student_id_foreign');
        $assignmentColumns = Schema::getColumnListing('student_zoom_assignments');
        $addAssignmentSession = ! in_array('zoom_session_id', $assignmentColumns, true);
        $addAssignmentRegistrant = ! in_array('registrant_id', $assignmentColumns, true);
        $addAssignmentPseudocode = ! in_array('pseudocode_snapshot', $assignmentColumns, true);
        $addAssignmentStatus = ! in_array('registration_status', $assignmentColumns, true);
        $addAssignmentRegisteredAt = ! in_array('registered_at', $assignmentColumns, true);
        $addAssignmentSource = ! in_array('registration_source', $assignmentColumns, true);
        $addAssignmentSessionForeign = $addAssignmentSession || ! Schema::hasForeignKey('student_zoom_assignments', 'student_zoom_assignments_zoom_session_id_foreign');
        $addAssignmentStudentSessionUnique = ! Schema::hasIndex('student_zoom_assignments', 'student_zoom_assignments_student_session_unique');
        $addAssignmentSessionRegistrantUnique = ! Schema::hasIndex('student_zoom_assignments', 'student_zoom_assignments_session_registrant_unique');
        $addLegacyGeneratedColumn = DB::connection()->getDriverName() === 'mysql' && ! in_array('legacy_student_id', $assignmentColumns, true);
        $addLegacyUnique = DB::connection()->getDriverName() === 'mysql'
            && ! Schema::hasIndex('student_zoom_assignments', 'student_zoom_assignments_legacy_student_unique');

        Schema::table('student_zoom_assignments', function (Blueprint $table) use (
            $hasStudentUnique,
            $hasStudentForeign,
            $addAssignmentSession,
            $addAssignmentRegistrant,
            $addAssignmentPseudocode,
            $addAssignmentStatus,
            $addAssignmentRegisteredAt,
            $addAssignmentSource,
            $addAssignmentSessionForeign,
            $addAssignmentStudentSessionUnique,
            $addAssignmentSessionRegistrantUnique,
            $addLegacyGeneratedColumn,
            $addLegacyUnique,
        ): void {
            if ($hasStudentUnique) {
                if ($hasStudentForeign) $table->dropForeign(['student_id']);
                $table->dropUnique(['student_id']);
            }

            if ($addAssignmentSession) $table->foreignId('zoom_session_id')->nullable()->after('student_id');
            if ($addAssignmentRegistrant) $table->string('registrant_id', 191)->nullable();
            if ($addAssignmentPseudocode) $table->string('pseudocode_snapshot', 14)->nullable();
            if ($addAssignmentStatus) $table->string('registration_status', 20)->default('manual');
            if ($addAssignmentRegisteredAt) $table->timestamp('registered_at')->nullable();
            if ($addAssignmentSource) $table->string('registration_source', 20)->default('manual');

            if ($addAssignmentSessionForeign) $table->foreign('zoom_session_id')->references('id')->on('zoom_sessions')->restrictOnDelete();
            if (! $hasStudentForeign || $hasStudentUnique) $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            if ($addAssignmentStudentSessionUnique) $table->unique(['student_id', 'zoom_session_id'], 'student_zoom_assignments_student_session_unique');
            if ($addAssignmentSessionRegistrantUnique) $table->unique(['zoom_session_id', 'registrant_id'], 'student_zoom_assignments_session_registrant_unique');

            if ($addLegacyGeneratedColumn) {
                $table->unsignedBigInteger('legacy_student_id')->nullable()
                    ->storedAs('CASE WHEN zoom_session_id IS NULL THEN student_id ELSE NULL END');
            }
            if ($addLegacyUnique) $table->unique('legacy_student_id', 'student_zoom_assignments_legacy_student_unique');
        });

        if (DB::connection()->getDriverName() === 'sqlite' && ! Schema::hasIndex('student_zoom_assignments', 'student_zoom_assignments_legacy_student_unique')) {
            DB::statement('CREATE UNIQUE INDEX student_zoom_assignments_legacy_student_unique ON student_zoom_assignments (student_id) WHERE zoom_session_id IS NULL');
        }

        Schema::create('zoom_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zoom_session_id')->constrained('zoom_sessions')->restrictOnDelete();
            $table->string('zoom_meeting_uuid', 191);
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('registrant_id', 191)->nullable();
            $table->string('participant_id', 191)->nullable();
            $table->string('participant_pseudocode', 14)->nullable();
            $table->string('participant_name', 255)->nullable();
            $table->string('participant_email')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('match_status', 24)->default('unmatched');
            $table->json('raw_data')->nullable();
            $table->string('dedupe_key', 64);
            $table->timestamps();
            $table->unique(['zoom_session_id', 'zoom_meeting_uuid', 'dedupe_key'], 'zoom_attendance_occurrence_participant_unique');
            $table->index(['student_id', 'joined_at']);
            $table->index(['zoom_session_id', 'zoom_meeting_uuid']);
            $table->index('registrant_id');
        });
    }

    public function down(): void
    {
        if (DB::table('zoom_batch_memberships')->exists()
            || DB::table('zoom_attendance_records')->exists()
            || DB::table('zoom_batches')->exists()
            || DB::table('zoom_sessions')->where(function ($query): void {
                $query->whereNotNull('zoom_batch_id')->orWhereNotNull('zoom_meeting_id')->orWhereNotNull('zoom_meeting_uuid')
                    ->orWhereNotNull('zoom_host_id')->orWhereNotNull('zoom_meeting_type')->orWhereNotNull('registration_type')
                    ->orWhereNotNull('recurrence_config')->orWhereNotNull('registration_url')->orWhereNotNull('meeting_status')
                    ->orWhereNotNull('last_synced_at')->orWhereNotNull('deleted_at');
            })->exists()
            || DB::table('student_zoom_assignments')->where(function ($query): void {
                $query->whereNotNull('zoom_session_id')->orWhereNotNull('registrant_id')->orWhereNotNull('pseudocode_snapshot')
                    ->orWhereNotNull('registered_at')->orWhere('registration_status', '<>', 'manual')
                    ->orWhere('registration_source', '<>', 'manual');
            })->exists()) {
            throw new RuntimeException('Zoom batch history exists; refusing to roll back and delete it.');
        }

        Schema::dropIfExists('zoom_attendance_records');

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS student_zoom_assignments_legacy_student_unique');
        }

        Schema::table('student_zoom_assignments', function (Blueprint $table): void {
            $table->dropUnique('student_zoom_assignments_student_session_unique');
            $table->dropUnique('student_zoom_assignments_session_registrant_unique');
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->dropUnique('student_zoom_assignments_legacy_student_unique');
            }
            $table->dropForeign(['zoom_session_id']);
            $table->dropColumn(['zoom_session_id', 'registrant_id', 'pseudocode_snapshot', 'registration_status', 'registered_at', 'registration_source']);
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->dropColumn('legacy_student_id');
            }
            $table->unique('student_id');
            $table->dropForeign(['student_id']);
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('zoom_sessions', function (Blueprint $table): void {
            $table->dropIndex('zoom_sessions_batch_schedule_index');
            $table->dropUnique(['zoom_meeting_id']);
            $table->dropForeign(['zoom_batch_id']);
            $table->dropColumn([
                'zoom_batch_id', 'zoom_meeting_id', 'zoom_meeting_uuid', 'zoom_host_id',
                'zoom_meeting_type', 'registration_type', 'recurrence_config', 'registration_url',
                'meeting_status', 'last_synced_at',
            ]);
            $table->dropSoftDeletes();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS zoom_batch_memberships_one_active_student');
        }

        Schema::dropIfExists('zoom_batch_memberships');
        Schema::dropIfExists('zoom_batches');
    }
};
