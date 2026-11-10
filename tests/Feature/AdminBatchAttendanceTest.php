<?php

use App\Models\User;
use App\Models\ZoomAttendanceOccurrence;
use App\Models\ZoomAttendanceOccurrenceStudent;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomAttendanceSetting;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Models\ZoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function batchAttendanceTestUser(string $role, string $email): User
{
    return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => $role]);
}

function batchAttendanceTestSetup(): array
{
    ZoomAttendanceSetting::create(['present_threshold' => 80, 'late_threshold' => 45]);
    $admin = batchAttendanceTestUser('admin', 'attendance-admin@example.test');
    $students = collect(range(1, 3))->map(fn ($n) => batchAttendanceTestUser('student', "attendance-student{$n}@example.test"));
    $batch = ZoomBatch::create(['name' => 'Attendance Batch', 'created_by' => $admin->id]);
    $students->each(fn (User $student) => ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now(), 'created_by' => $admin->id]));
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Batch meeting', 'meeting_url' => 'https://zoom.us/j/123', 'scheduled_at' => now(), 'zoom_meeting_id' => '123', 'meeting_status' => 'active']);
    $occurrence = ZoomAttendanceOccurrence::create(['zoom_session_id' => $session->id, 'meeting_uuid' => 'attendance-occurrence', 'started_at' => now()->subDay(), 'duration_seconds' => 1000, 'sync_status' => 'completed']);
    ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => $occurrence->meeting_uuid, 'student_id' => $students[0]->id, 'participant_name' => 'Student 1', 'duration_seconds' => 800, 'match_status' => 'matched']);
    ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => $occurrence->meeting_uuid, 'student_id' => $students[1]->id, 'participant_name' => 'Student 2', 'duration_seconds' => 600, 'match_status' => 'matched']);
    app(\App\Services\ZoomAttendanceClassifier::class)->classify($occurrence);
    expect(ZoomAttendanceOccurrenceStudent::where('occurrence_id', $occurrence->id)->orderBy('student_id')->pluck('duration_seconds')->all())->toBe([800, 600, 0]);
    return [$admin, $students, $batch, $occurrence];
}

it('shows all batch students including absentees and keeps classifications stable until explicit recalculation', function () {
    [$admin, $students, $batch, $occurrence] = batchAttendanceTestSetup();
    $this->actingAs($admin)->getJson('/api/admin/batch-attendance?batch_id='.$batch->id)->assertOk()
        ->assertJsonCount(3, 'rows.data')->assertJsonPath('rows.total', 3)->assertJsonPath('rows.per_page', 25)->assertJsonFragment(['student_id' => $students[0]->id, 'occurrence_'.$occurrence->id => 'O'])
        ->assertJsonFragment(['student_id' => $students[1]->id, 'occurrence_'.$occurrence->id => 'L'])
        ->assertJsonFragment(['student_id' => $students[2]->id, 'occurrence_'.$occurrence->id => 'X'])
        ->assertJsonPath('occurrences.0.summary.O', 1)->assertJsonPath('occurrences.0.summary.X', 1);
    $this->get('/api/admin/batch-attendance?batch_id='.$batch->id.'&format=xlsx')->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->putJson('/api/admin/batch-attendance/settings', ['present_threshold' => 45, 'late_threshold' => 45])->assertUnprocessable();
    $this->putJson('/api/admin/batch-attendance/settings', ['present_threshold' => 70, 'late_threshold' => 65])->assertOk();
    expect(ZoomAttendanceOccurrenceStudent::where('occurrence_id', $occurrence->id)->where('student_id', $students[1]->id)->value('attendance_code'))->toBe('L');
    $this->postJson('/api/admin/batch-attendance/recalculate', ['batch_id' => $batch->id])->assertOk();
    expect(ZoomAttendanceOccurrenceStudent::where('occurrence_id', $occurrence->id)->where('student_id', $students[1]->id)->value('attendance_code'))->toBe('Review');
});

it('paginates batch attendance students while retaining whole-occurrence summaries', function () {
    [$admin, $students, $batch, $occurrence] = batchAttendanceTestSetup();
    foreach (range(4, 27) as $number) {
        $student = batchAttendanceTestUser('student', "attendance-page{$number}@example.test");
        ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now(), 'created_by' => $admin->id]);
    }

    $this->actingAs($admin)->getJson('/api/admin/batch-attendance?batch_id='.$batch->id.'&page=2')->assertOk()
        ->assertJsonPath('rows.current_page', 2)->assertJsonPath('rows.last_page', 2)
        ->assertJsonPath('rows.total', 27)->assertJsonCount(2, 'rows.data')
        ->assertJsonPath('occurrences.0.summary.O', 1)->assertJsonPath('occurrences.0.summary.X', 1);
});

it('records manual make-up attendance with actor and timestamp and preserves it during recalculation only when applicable', function () {
    [$admin, $students, $batch, $occurrence] = batchAttendanceTestSetup();
    $this->actingAs($admin)->putJson("/api/admin/batch-attendance/{$occurrence->id}/students/{$students[2]->id}", ['attendance_code' => 'M'])->assertOk();
    $manual = ZoomAttendanceOccurrenceStudent::where('occurrence_id', $occurrence->id)->where('student_id', $students[2]->id)->firstOrFail();
    expect($manual->attendance_code)->toBe('M')->and($manual->is_manual)->toBeTrue()->and($manual->updated_by)->toBe($admin->id)->and($manual->manually_updated_at)->not->toBeNull();
    app(\App\Services\ZoomAttendanceClassifier::class)->classify($occurrence->fresh());
    expect($manual->fresh()->attendance_code)->toBe('M')->and($manual->fresh()->is_manual)->toBeTrue();
    app(\App\Services\ZoomAttendanceClassifier::class)->classify($occurrence->fresh(), true);
    expect($manual->fresh()->attendance_code)->toBe('X')->and($manual->fresh()->is_manual)->toBeFalse();
});

it('allows ordinary admins to use batch reports but reserves Zoom integration controls for super admins', function () {
    $admin = batchAttendanceTestUser('admin', 'attendance-ordinary-admin@example.test');
    $superAdmin = batchAttendanceTestUser('super_admin', 'attendance-super-admin@example.test');
    $this->actingAs($admin)->getJson('/api/admin/batch-attendance/settings')->assertOk();
    $this->actingAs($admin)->postJson('/api/admin/zoom/test-connection')->assertForbidden();
    $this->actingAs($superAdmin)->postJson('/api/admin/zoom/test-connection')->assertStatus(422);
});
