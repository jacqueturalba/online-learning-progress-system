<?php

use App\Models\StudentZoomAssignment;
use App\Models\User;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Models\ZoomSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function zoomFoundationAdmin(): User
{
    return User::create(['name' => 'Zoom Admin', 'email' => 'zoom-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
}

function zoomFoundationStudent(string $email = 'zoom-student@example.test'): User
{
    return User::create(['name' => 'Zoom Student', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

function zoomFoundationBatch(User $admin, string $name = 'Autumn cohort'): ZoomBatch
{
    return ZoomBatch::create(['name' => $name, 'created_by' => $admin->id]);
}

function zoomFoundationSession(ZoomBatch $batch, string $meetingId = '98765432101'): ZoomSession
{
    return ZoomSession::create([
        'zoom_batch_id' => $batch->id,
        'title' => $batch->name.' recurring meeting',
        'meeting_url' => 'https://zoom.us/j/'.$meetingId,
        'scheduled_at' => now()->addDay(),
        'zoom_meeting_id' => $meetingId,
        'zoom_meeting_uuid' => 'meeting-uuid-before-first-occurrence',
        'zoom_meeting_type' => 8,
        'registration_type' => 1,
        'recurrence_config' => ['type' => 2, 'end_times' => 52, 'weekly_days' => '2'],
        'meeting_status' => 'active',
    ]);
}

it('records batch membership history and prevents duplicate active membership in a batch', function () {
    $admin = zoomFoundationAdmin();
    $student = zoomFoundationStudent();
    $batch = zoomFoundationBatch($admin);
    $membership = ZoomBatchMembership::create([
        'zoom_batch_id' => $batch->id,
        'student_id' => $student->id,
        'status' => 'active',
        'joined_at' => now(),
        'created_by' => $admin->id,
    ]);

    expect($batch->memberships()->first()->is($membership))->toBeTrue()
        ->and($batch->students()->first()->is($student))->toBeTrue()
        ->and($membership->student->is($student))->toBeTrue();

    expect(fn () => ZoomBatchMembership::create([
        'zoom_batch_id' => $batch->id,
        'student_id' => $student->id,
        'status' => 'active',
        'joined_at' => now(),
    ]))->toThrow(QueryException::class);

    $membership->update(['status' => 'transferred', 'ended_at' => now()]);
    $newMembership = ZoomBatchMembership::create([
        'zoom_batch_id' => $batch->id,
        'student_id' => $student->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    expect($batch->memberships()->count())->toBe(2)
        ->and($newMembership->status)->toBe('active');
});

it('relates recurring meetings to batches and preserves Zoom identifiers as strings', function () {
    $admin = zoomFoundationAdmin();
    $batch = zoomFoundationBatch($admin);
    $meetingId = '12345678901234567890';
    $occurrenceUuid = 'occurrence/uuid//with-characters-and-a-long-value-1234567890';
    $session = zoomFoundationSession($batch, $meetingId);
    $session->update(['zoom_meeting_uuid' => $occurrenceUuid]);
    $largerBatch = ZoomBatch::create(['name' => 'Extended cohort', 'status' => 'active', 'capacity' => 75]);

    expect($session->fresh()->zoom_meeting_id)->toBe($meetingId)
        ->and($session->fresh()->zoom_meeting_uuid)->toBe($occurrenceUuid)
        ->and($session->fresh()->recurrence_config)->toBe(['type' => 2, 'end_times' => 52, 'weekly_days' => '2'])
        ->and($session->zoomBatch->is($batch))->toBeTrue()
        ->and($batch->zoomSessions()->first()->is($session))->toBeTrue()
        ->and($largerBatch->capacity)->toBe(75);
});

it('allows one student to have registrations for multiple meetings while keeping legacy manual links accessible', function () {
    $admin = zoomFoundationAdmin();
    $student = zoomFoundationStudent();
    $firstSession = zoomFoundationSession(zoomFoundationBatch($admin, 'First cohort'));
    $secondSession = zoomFoundationSession(zoomFoundationBatch($admin, 'Second cohort'), '98765432102');

    $manual = StudentZoomAssignment::create([
        'student_id' => $student->id,
        'zoom_url' => 'https://zoom.us/j/manual-student-link',
        'is_active' => true,
        'assigned_by' => $admin->id,
    ]);
    $firstRegistration = StudentZoomAssignment::create([
        'student_id' => $student->id,
        'zoom_session_id' => $firstSession->id,
        'zoom_url' => 'https://zoom.us/j/unique-first-registration',
        'registrant_id' => 'registrant-001',
        'pseudocode_snapshot' => $student->pseudocode,
        'registration_status' => 'registered',
        'registration_source' => 'zoom_api',
        'registered_at' => now(),
        'is_active' => true,
    ]);
    $secondRegistration = StudentZoomAssignment::create([
        'student_id' => $student->id,
        'zoom_session_id' => $secondSession->id,
        'zoom_url' => 'https://zoom.us/j/unique-second-registration',
        'registrant_id' => 'registrant-002',
        'pseudocode_snapshot' => $student->pseudocode,
        'registration_status' => 'registered',
        'registration_source' => 'zoom_api',
        'registered_at' => now(),
        'is_active' => true,
    ]);

    expect($student->zoomAssignment()->first()->is($manual))->toBeTrue()
        ->and($student->zoomAssignments()->count())->toBe(3)
        ->and($firstSession->registrations()->first()->is($firstRegistration))->toBeTrue()
        ->and($secondSession->registrations()->first()->is($secondRegistration))->toBeTrue();

    expect(fn () => StudentZoomAssignment::create([
        'student_id' => $student->id,
        'zoom_url' => 'https://zoom.us/j/duplicate-legacy-link',
        'is_active' => true,
    ]))->toThrow(QueryException::class);

    expect(fn () => StudentZoomAssignment::create([
        'student_id' => $student->id,
        'zoom_session_id' => $firstSession->id,
        'zoom_url' => 'https://zoom.us/j/duplicate-registration',
        'registrant_id' => 'registrant-003',
        'pseudocode_snapshot' => $student->pseudocode,
        'registration_status' => 'registered',
        'registered_at' => now(),
        'is_active' => true,
        'registration_source' => 'zoom_api',
    ]))->toThrow(QueryException::class);

    $this->actingAs($admin)->getJson('/api/admin/zoom?search='.$student->pseudocode)
        ->assertOk()
        ->assertJsonPath('students.data.0.zoom_assignment.zoom_url', $manual->zoom_url);

    $this->actingAs($admin)->putJson('/api/admin/zoom/assignments/'.$student->id, [
        'zoom_url' => 'https://zoom.us/j/updated-manual-student-link',
        'is_active' => true,
    ])->assertOk();

    expect($student->fresh()->zoomAssignment->zoom_url)->toBe('https://zoom.us/j/updated-manual-student-link')
        ->and($firstRegistration->fresh()->zoom_url)->toBe('https://zoom.us/j/unique-first-registration')
        ->and($secondRegistration->fresh()->zoom_url)->toBe('https://zoom.us/j/unique-second-registration');
});

it('stores attendance by occurrence and prevents duplicate participant sessions', function () {
    $admin = zoomFoundationAdmin();
    $student = zoomFoundationStudent();
    $session = zoomFoundationSession(zoomFoundationBatch($admin));
    $occurrenceUuid = 'occurrence/uuid//with-a-value-that-is-longer-than-the-default-uuid-field';
    $attendance = ZoomAttendanceRecord::create([
        'zoom_session_id' => $session->id,
        'zoom_meeting_uuid' => $occurrenceUuid,
        'student_id' => $student->id,
        'registrant_id' => 'registrant-001',
        'participant_id' => 'participant-001',
        'participant_pseudocode' => $student->pseudocode,
        'participant_name' => $student->pseudocode,
        'participant_email' => $student->email,
        'joined_at' => now()->startOfMinute(),
        'duration_seconds' => 3600,
        'match_status' => 'matched',
        'raw_data' => ['name' => $student->pseudocode],
    ]);

    expect($session->attendanceRecords()->first()->is($attendance))->toBeTrue()
        ->and($attendance->student->is($student))->toBeTrue()
        ->and($attendance->zoom_meeting_uuid)->toBe($occurrenceUuid)
        ->and($attendance->raw_data)->toBe(['name' => $student->pseudocode]);

    expect(fn () => ZoomAttendanceRecord::create([
        'zoom_session_id' => $session->id,
        'zoom_meeting_uuid' => $occurrenceUuid,
        'student_id' => $student->id,
        'registrant_id' => 'registrant-001',
        'participant_id' => 'participant-001',
        'participant_pseudocode' => $student->pseudocode,
        'participant_name' => $student->pseudocode,
        'participant_email' => $student->email,
        'joined_at' => now()->startOfMinute(),
        'duration_seconds' => 3600,
        'match_status' => 'matched',
    ]))->toThrow(QueryException::class);

    $rejoin = ZoomAttendanceRecord::create([
        'zoom_session_id' => $session->id,
        'zoom_meeting_uuid' => $occurrenceUuid,
        'student_id' => $student->id,
        'participant_id' => 'participant-002',
        'participant_name' => $student->pseudocode,
        'joined_at' => now()->addHour(),
        'match_status' => 'manually_matched',
    ]);
    $nextOccurrence = ZoomAttendanceRecord::create([
        'zoom_session_id' => $session->id,
        'zoom_meeting_uuid' => 'occurrence-uuid-two',
        'participant_id' => 'participant-001',
        'participant_name' => $student->pseudocode,
        'joined_at' => now()->addWeek(),
    ]);

    expect($rejoin->student->is($student))->toBeTrue()
        ->and($session->attendanceRecords()->count())->toBe(3)
        ->and($nextOccurrence->zoom_meeting_uuid)->toBe('occurrence-uuid-two')
        ->and($nextOccurrence->match_status)->toBe('unmatched');
});
