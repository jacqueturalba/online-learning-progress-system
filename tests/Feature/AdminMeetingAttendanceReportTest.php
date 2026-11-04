<?php

use App\Models\User;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Models\ZoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('groups occurrence joins, retains the historical batch and keeps unmatched participants unassigned', function () {
    $admin = User::create(['name' => 'Attendance Admin', 'email' => 'attendance-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
    $student = User::create(['name' => 'Attendance Student', 'email' => 'attendance-student@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
    $batch = ZoomBatch::create(['name' => 'Attendance batch', 'status' => 'completed']);
    ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'status' => 'transferred', 'joined_at' => now()->subDays(20), 'ended_at' => now()->subDays(10)]);
    ZoomBatch::create(['name' => 'Current batch', 'status' => 'active']);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Recurring class', 'meeting_url' => 'https://zoom.us/j/1', 'scheduled_at' => now()->subDays(12), 'zoom_meeting_id' => '12345678901', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'recurrence_config' => ['frequency' => 'weekly'], 'meeting_status' => 'ended']);
    $joinedAt = now()->subDays(12)->setTime(9, 0);
    foreach ([[$joinedAt, 120], [$joinedAt->copy()->addMinutes(10), 180]] as [$join, $duration]) {
        ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => 'occurrence-one', 'student_id' => $student->id, 'registrant_id' => 'registrant-1', 'participant_id' => 'participant-1', 'participant_pseudocode' => $student->pseudocode, 'participant_name' => $student->pseudocode, 'joined_at' => $join, 'left_at' => $join->copy()->addSeconds($duration), 'duration_seconds' => $duration, 'match_status' => 'matched', 'raw_data' => ['occurrence_start_time' => $joinedAt->toIso8601String()]]);
    }
    ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => 'occurrence-one', 'participant_id' => 'unmatched-1', 'participant_name' => 'Guest attendee', 'participant_email' => 'guest@example.test', 'joined_at' => $joinedAt->copy()->addMinutes(20), 'left_at' => $joinedAt->copy()->addMinutes(25), 'duration_seconds' => 300, 'match_status' => 'unmatched', 'raw_data' => ['occurrence_start_time' => $joinedAt->toIso8601String()]]);

    $response = $this->actingAs($admin)->getJson('/api/admin/reports?report=meeting_attendance&batch_id='.$batch->id.'&date_from='.$joinedAt->toDateString())->assertOk();
    expect($response->json('rows.total'))->toBe(2)
        ->and($response->json('attendance_summary.occurrence_count'))->toBe(1)
        ->and($response->json('attendance_summary.matched_count'))->toBe(1)
        ->and($response->json('attendance_summary.unmatched_count'))->toBe(1);
    $matched = collect($response->json('rows.data'))->firstWhere('attendance_status', 'Matched');
    $unmatched = collect($response->json('rows.data'))->firstWhere('attendance_status', 'Unmatched');
    expect($matched['batch_name'])->toBe('Attendance batch')
        ->and($matched['total_duration_seconds'])->toBe(300)
        ->and($matched['number_of_joins'])->toBe(2)
        ->and($unmatched['student_name'])->toBe('Guest attendee')
        ->and($unmatched['pseudocode'])->toBeNull();

    $excel = $this->actingAs($admin)->get('/api/admin/reports?report=meeting_attendance&format=xlsx&batch_id='.$batch->id);
    $excel->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($excel->getContent())->toStartWith("PK\x03\x04")
        ->and($excel->getContent())->toContain('Guest attendee')
        ->and($excel->getContent())->toContain('Meeting: Recurring class')
        ->and($excel->getContent())->toContain('Batch: Attendance batch');
});
