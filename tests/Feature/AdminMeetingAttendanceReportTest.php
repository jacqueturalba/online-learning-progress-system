<?php

use App\Models\User;
use App\Models\Category;
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
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Recurring class', 'meeting_url' => 'https://zoom.us/j/1', 'scheduled_at' => now()->addDays(2), 'zoom_meeting_id' => '12345678901', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'recurrence_config' => ['frequency' => 'weekly'], 'meeting_status' => 'ended']);
    $joinedAt = now()->subDays(12)->setTime(9, 0);
    foreach ([[$joinedAt, 120], [$joinedAt->copy()->addMinutes(10), 180]] as [$join, $duration]) {
        ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => 'occurrence-one', 'student_id' => $student->id, 'registrant_id' => 'registrant-1', 'participant_id' => 'participant-1', 'participant_pseudocode' => $student->pseudocode, 'participant_name' => $student->pseudocode, 'joined_at' => $join, 'left_at' => $join->copy()->addSeconds($duration), 'duration_seconds' => $duration, 'match_status' => 'matched', 'raw_data' => ['occurrence_start_time' => $joinedAt->toIso8601String()]]);
    }
    ZoomAttendanceRecord::create(['zoom_session_id' => $session->id, 'zoom_meeting_uuid' => 'occurrence-one', 'participant_id' => 'unmatched-1', 'participant_name' => 'Guest attendee', 'participant_email' => 'guest@example.test', 'joined_at' => $joinedAt->copy()->addMinutes(20), 'left_at' => $joinedAt->copy()->addMinutes(25), 'duration_seconds' => 300, 'match_status' => 'unmatched', 'raw_data' => ['occurrence_start_time' => $joinedAt->toIso8601String()]]);

    $response = $this->actingAs($admin)->getJson('/api/admin/reports?report=meeting_attendance&batch_id='.$batch->id.'&date_from='.$joinedAt->toDateString().'&date_to='.$joinedAt->toDateString())->assertOk();
    expect($response->json('rows.total'))->toBe(2)
        ->and($response->json('attendance_summary.occurrence_count'))->toBe(1)
        ->and($response->json('attendance_summary.matched_count'))->toBe(1)
        ->and($response->json('attendance_summary.unmatched_count'))->toBe(1);
    $matched = collect($response->json('rows.data'))->firstWhere('attendance_status', 'Matched');
    $unmatched = collect($response->json('rows.data'))->firstWhere('attendance_status', 'Unmatched');
    expect($matched['batch_name'])->toBe('Attendance batch')
        ->and($matched['occurrence_date'])->toContain($joinedAt->toDateString())
        ->and($matched['participant_name'])->toBe($student->pseudocode)
        ->and($matched['total_duration_seconds'])->toBe(300)
        ->and($matched['number_of_joins'])->toBe(2)
        ->and($unmatched['student_name'])->toBe('Guest attendee')
        ->and($unmatched['pseudocode'])->toBeNull();
    $meetingOption = collect($response->json('filters.meetings'))->firstWhere('id', $session->id);
    expect($meetingOption['occurrence_dates'])->toHaveCount(1)
        ->and($meetingOption['occurrence_dates'][0])->toContain($joinedAt->toDateString())
        ->and($meetingOption['occurrence_dates'][0])->not->toContain($session->scheduled_at->toDateString());

    $excel = $this->actingAs($admin)->get('/api/admin/reports?report=meeting_attendance&format=xlsx&batch_id='.$batch->id);
    $excel->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($excel->getContent())->toStartWith("PK\x03\x04")
        ->and($excel->getContent())->toContain('Guest attendee')
        ->and($excel->getContent())->toContain('Meeting: Recurring class')
        ->and($excel->getContent())->toContain('Batch: Attendance batch');
});

it('filters historical occurrences by category and paginates 25 participants while exporting every filtered row', function () {
    $admin = User::create(['name' => 'Pagination Admin', 'email' => 'attendance-page-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
    $category = Category::create(['name' => 'Attendance category', 'sort_order' => 1, 'is_active' => true]);
    $otherCategory = Category::create(['name' => 'Other category', 'sort_order' => 2, 'is_active' => true]);
    $batch = ZoomBatch::create(['name' => 'Historical batch', 'status' => 'completed']);
    $futureSession = ZoomSession::create(['category_id' => $category->id, 'zoom_batch_id' => $batch->id, 'title' => 'Future weekly meeting',
        'meeting_url' => 'https://zoom.us/j/2', 'scheduled_at' => now()->addDays(5), 'zoom_meeting_id' => '22222222222', 'zoom_meeting_type' => 8]);
    $otherSession = ZoomSession::create(['category_id' => $otherCategory->id, 'zoom_batch_id' => $batch->id, 'title' => 'Other meeting',
        'meeting_url' => 'https://zoom.us/j/3', 'scheduled_at' => now()->subDays(1), 'zoom_meeting_id' => '33333333333', 'zoom_meeting_type' => 8]);

    for ($index = 1; $index <= 27; $index++) {
        ZoomAttendanceRecord::create(['zoom_session_id' => $futureSession->id, 'zoom_meeting_uuid' => 'historical-uuid',
            'participant_id' => 'historical-participant-'.$index, 'participant_name' => $index === 27 ? 'Last participant' : 'Participant '.$index,
            'joined_at' => now()->subDays(3)->setTime(10, 0)->addMinutes($index), 'left_at' => now()->subDays(3)->setTime(10, 1)->addMinutes($index),
            'duration_seconds' => 60, 'match_status' => 'unmatched', 'raw_data' => ['occurrence_start_time' => now()->subDays(3)->setTime(9, 55)->toIso8601String()]]);
    }
    ZoomAttendanceRecord::create(['zoom_session_id' => $otherSession->id, 'zoom_meeting_uuid' => 'other-uuid', 'participant_id' => 'other-participant',
        'participant_name' => 'Other category participant', 'joined_at' => now()->subDays(3), 'duration_seconds' => 5, 'match_status' => 'unmatched',
        'raw_data' => ['occurrence_start_time' => now()->subDays(3)->toIso8601String()]]);

    $base = '/api/admin/reports?report=meeting_attendance&category_id='.$category->id.'&date_from='.now()->subDays(3)->toDateString().'&date_to='.now()->subDays(3)->toDateString();
    $pageOne = $this->actingAs($admin)->getJson($base)->assertOk();
    expect($pageOne->json('rows.total'))->toBe(27)
        ->and($pageOne->json('rows.per_page'))->toBe(25)
        ->and($pageOne->json('rows.last_page'))->toBe(2)
        ->and($pageOne->json('attendance_summary.unmatched_count'))->toBe(27)
        ->and(collect($pageOne->json('filters.meetings'))->pluck('title')->all())->toBe(['Future weekly meeting']);
    $this->getJson($base.'&page=2')->assertOk()->assertJsonCount(2, 'rows.data');
    $xlsx = $this->get($base.'&format=xlsx')->assertOk();
    expect($xlsx->getContent())->toContain('Last participant');
    expect($xlsx->getContent())->not->toContain('Other category participant');
});
