<?php

use App\Models\StudentZoomAssignment;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Models\ZoomIntegrationSetting;
use App\Models\ZoomSession;
use App\Models\ZoomAttendanceRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function zoomIntegrationUser(string $role = 'student', string $email = 'zoom-integration@example.test', ?string $name = null): User
{
    return User::create(['name' => $name ?? ucfirst($role).' Integration', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => $role]);
}

beforeEach(function () {
    Cache::flush();
    ZoomIntegrationSetting::create(['account_id' => 'zoom-account', 'client_id' => 'zoom-client', 'client_secret' => 'zoom-secret']);
});

it('tests the saved Zoom connection without returning credentials or access tokens', function () {
    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'private-token-value', 'expires_in' => 3600], 200),
        'https://api.zoom.us/v2/users/me' => Http::response(['id' => 'host-1', 'display_name' => 'Zoom Host', 'email' => 'host@example.test'], 200),
    ]);
    $admin = zoomIntegrationUser('admin', 'zoom-admin-integration@example.test');
    $this->actingAs($admin)->postJson('/api/admin/zoom/test-connection')->assertOk()
        ->assertJsonPath('connected', true)->assertJsonPath('message', 'Connected to Zoom as Zoom Host.')
        ->assertJsonMissing(['access_token' => 'private-token-value']);
});

it('creates a fixed-time recurring Zoom meeting and saves it against the batch', function () {
    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'private-token-value', 'expires_in' => 3600], 200),
        'https://api.zoom.us/v2/users/host-1/meetings' => Http::response([
            'id' => 98765432101, 'uuid' => 'uuid-abc', 'host_id' => 'host-1', 'topic' => 'Weekly lesson',
            'join_url' => 'https://zoom.us/j/98765432101', 'registration_url' => 'https://zoom.us/meeting/register/example',
            'start_time' => '2027-01-04T09:00:00Z',
        ], 201),
    ]);
    $admin = zoomIntegrationUser('admin', 'zoom-admin-create@example.test');
    $batch = ZoomBatch::create(['name' => 'Winter batch', 'created_by' => $admin->id]);
    $this->actingAs($admin)->postJson("/api/admin/zoom/batches/{$batch->id}/meeting", [
        'topic' => 'Weekly lesson', 'description' => 'Lesson room', 'host_id' => 'host-1',
        'start_time' => now()->addDays(3)->setTime(9, 0)->format('Y-m-d\TH:i'), 'duration' => 60,
        'timezone' => 'Asia/Singapore', 'frequency' => 'weekly', 'repeat_interval' => 1,
        'weekly_days' => [2], 'end_date' => now()->addDays(60)->format('Y-m-d'), 'registration_enabled' => true,
    ])->assertCreated()->assertJsonPath('batch.session.meeting_id', '98765432101')
        ->assertJsonPath('batch.session.meeting_type', 8)
        ->assertJsonPath('batch.session.registration_enabled', true);

    $session = ZoomSession::query()->where('zoom_batch_id', $batch->id)->firstOrFail();
    expect($session->zoom_meeting_uuid)->toBe('uuid-abc')
        ->and($session->registration_type)->toBe(1)
        ->and($session->recurrence_config['frequency'])->toBe('weekly')
        ->and($session->recurrence_config['timezone'])->toBe('Asia/Singapore');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/users/host-1/meetings')
        && $request['type'] === 8 && $request['settings']['registration_type'] === 1);
});

it('registers each member independently, retries failed rows, and skips a previously successful registration', function () {
    Http::fake(function ($request) {
        static $secondAttempts = 0;
        if ($request->url() === 'https://zoom.us/oauth/token') return Http::response(['access_token' => 'private-token-value', 'expires_in' => 3600], 200);
        if (str_ends_with($request->url(), '/registrants')) {
            if ($request['email'] === 'first@example.test') return Http::response(['registrant_id' => 'reg-first', 'join_url' => 'https://zoom.us/r/unique-first'], 201);
            if ($request['email'] === 'second@example.test' && ++$secondAttempts === 1) return Http::response(['code' => 3000, 'message' => 'Registration rejected'], 400);
            return Http::response(['registrant_id' => 'reg-second', 'join_url' => 'https://zoom.us/r/unique-second'], 201);
        }
        return Http::response([], 404);
    });
    $admin = zoomIntegrationUser('admin', 'zoom-admin-register@example.test');
    $first = zoomIntegrationUser('student', 'first@example.test', 'Avery Example');
    $second = zoomIntegrationUser('student', 'second@example.test', 'Taylor Student');
    $batch = ZoomBatch::create(['name' => 'Registration batch', 'created_by' => $admin->id]);
    foreach ([$first, $second] as $student) ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'joined_at' => now(), 'created_by' => $admin->id]);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Batch meeting', 'meeting_url' => 'https://zoom.us/j/main', 'scheduled_at' => now()->addDay(), 'zoom_meeting_id' => '12345678901', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'registration_url' => 'https://zoom.us/register', 'meeting_status' => 'active']);
    $endpoint = "/api/admin/zoom/batches/{$batch->id}/registrations";

    $this->actingAs($admin)->postJson($endpoint, ['student_ids' => [$first->id, $second->id]])->assertOk()
        ->assertJsonPath('results.0.status', 'registered')->assertJsonPath('results.1.status', 'failed');
    expect(StudentZoomAssignment::where('student_id', $first->id)->where('zoom_session_id', $session->id)->count())->toBe(1)
        ->and(StudentZoomAssignment::where('student_id', $second->id)->where('zoom_session_id', $session->id)->value('registration_error'))->toBe('Registration rejected');

    $this->postJson($endpoint, ['student_ids' => [$second->id]])->assertOk()->assertJsonPath('results.0.status', 'registered');
    $requestsBeforeRetry = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/registrants'))->count();
    $this->postJson($endpoint, ['student_ids' => [$first->id]])->assertOk()->assertJsonPath('results.0.message', 'Already registered.');
    $requestsAfterRetry = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/registrants'))->count();
    expect($requestsAfterRetry)->toBe($requestsBeforeRetry)
        ->and(StudentZoomAssignment::where('zoom_session_id', $session->id)->count())->toBe(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/registrants')
        && $request['first_name'] === $first->pseudocode
        && $request['last_name'] === 'AE'
        && $request['email'] === 'first@example.test');
});

it('records a missing profile name and allows retry after the profile is completed', function () {
    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'private-token-value', 'expires_in' => 3600], 200),
        'https://api.zoom.us/v2/meetings/*/registrants' => Http::response(['registrant_id' => 'reg-after-name-fix', 'join_url' => 'https://zoom.us/r/after-name-fix'], 201),
    ]);
    $admin = zoomIntegrationUser('admin', 'zoom-admin-name-check@example.test');
    $student = zoomIntegrationUser('student', 'name-check@example.test', '');
    $pseudocode = $student->pseudocode;
    $batch = ZoomBatch::create(['name' => 'Name validation batch', 'created_by' => $admin->id]);
    ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'joined_at' => now(), 'created_by' => $admin->id]);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Batch meeting', 'meeting_url' => 'https://zoom.us/j/main', 'scheduled_at' => now()->addDay(), 'zoom_meeting_id' => '12345678904', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'registration_url' => 'https://zoom.us/register', 'meeting_status' => 'active']);
    $endpoint = "/api/admin/zoom/batches/{$batch->id}/registrations";

    $this->actingAs($admin)->postJson($endpoint, ['student_ids' => [$student->id]])->assertOk()
        ->assertJsonPath('results.0.status', 'failed');
    $this->getJson('/api/admin/zoom?status=failed')->assertOk()->assertJsonPath('students.data.0.id', $student->id);
    $assignment = StudentZoomAssignment::where('student_id', $student->id)->where('zoom_session_id', $session->id)->firstOrFail();
    expect($assignment->registration_error)->toBe('The student profile needs a name before Zoom registration can continue.')
        ->and($assignment->pseudocode_snapshot)->toBe($pseudocode);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/registrants'));

    $student->update(['name' => 'Morgan Completed']);
    $this->postJson($endpoint, ['student_ids' => [$student->id]])->assertOk()->assertJsonPath('results.0.status', 'registered');
    expect($student->fresh()->pseudocode)->toBe($pseudocode)
        ->and($assignment->fresh()->registration_error)->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/registrants')
        && $request['first_name'] === $pseudocode
        && $request['last_name'] === 'MC'
        && $request['email'] === 'name-check@example.test');
});

it('limits students to their own private registered Zoom links', function () {
    $owner = zoomIntegrationUser('student', 'zoom-owner@example.test');
    $other = zoomIntegrationUser('student', 'zoom-other@example.test');
    $batch = ZoomBatch::create(['name' => 'Private link batch']);
    ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $owner->id, 'joined_at' => now()]);
    ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $other->id, 'joined_at' => now()]);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Private meeting', 'meeting_url' => 'https://zoom.us/j/main', 'scheduled_at' => now(), 'zoom_meeting_id' => '12345678902', 'zoom_meeting_type' => 8, 'registration_type' => 1]);
    foreach ([[$owner, 'https://zoom.us/r/owner-link'], [$other, 'https://zoom.us/r/other-link']] as [$student, $url]) {
        StudentZoomAssignment::create(['student_id' => $student->id, 'zoom_session_id' => $session->id, 'zoom_url' => $url, 'is_active' => true, 'registrant_id' => 'reg-'.$student->id, 'pseudocode_snapshot' => $student->pseudocode, 'registration_status' => 'registered', 'registered_at' => now(), 'registration_source' => 'zoom_api']);
    }
    $this->actingAs($owner)->getJson('/api/my-zoom')->assertOk()
        ->assertJsonPath('meetings.0.join_url', 'https://zoom.us/r/owner-link')
        ->assertDontSee('https://zoom.us/r/other-link');
});

it('transfers a student while retaining the previous membership and meeting assignment', function () {
    $admin = zoomIntegrationUser('admin', 'zoom-admin-transfer@example.test');
    $student = zoomIntegrationUser('student', 'zoom-transfer@example.test');
    $source = ZoomBatch::create(['name' => 'Source batch', 'created_by' => $admin->id]);
    $target = ZoomBatch::create(['name' => 'Target batch', 'created_by' => $admin->id]);
    $membership = ZoomBatchMembership::create(['zoom_batch_id' => $source->id, 'student_id' => $student->id, 'joined_at' => now(), 'created_by' => $admin->id]);
    $session = ZoomSession::create(['zoom_batch_id' => $source->id, 'title' => 'Previous meeting', 'meeting_url' => 'https://zoom.us/j/source', 'scheduled_at' => now(), 'zoom_meeting_id' => '12345678903', 'zoom_meeting_type' => 8, 'registration_type' => 1]);
    $registration = StudentZoomAssignment::create(['student_id' => $student->id, 'zoom_session_id' => $session->id, 'zoom_url' => 'https://zoom.us/r/old-registration', 'is_active' => true, 'registrant_id' => 'old-reg', 'pseudocode_snapshot' => $student->pseudocode, 'registration_status' => 'registered', 'registered_at' => now(), 'registration_source' => 'zoom_api']);
    $this->actingAs($admin)->postJson("/api/admin/zoom/batches/{$source->id}/students/{$membership->id}/transfer", ['target_batch_id' => $target->id])->assertOk();

    expect($membership->fresh()->status)->toBe('transferred')
        ->and(ZoomBatchMembership::query()->where('zoom_batch_id', $target->id)->where('student_id', $student->id)->where('status', 'active')->exists())->toBeTrue()
        ->and($session->fresh()->zoom_meeting_id)->toBe('12345678903')
        ->and($registration->fresh()->is_active)->toBeFalse();
});

it('syncs recurring occurrence attendance, sums rejoin sessions, matches registrants and pseudocodes, and deduplicates repeated syncs', function () {
    $student = zoomIntegrationUser('student', 'attendance@example.test', 'Jordan Smith Miller');
    Http::fake(function ($request) use ($student) {
        if ($request->url() === 'https://zoom.us/oauth/token') return Http::response(['access_token' => 'private-token-value', 'expires_in' => 3600], 200);
        if (str_contains($request->url(), '/report/history_meetings')) return Http::response(['history_meetings' => [
            ['meeting_id' => '12345678910', 'meeting_uuid' => 'occurrence-one', 'start_time' => now()->subDays(8)->toIso8601String()],
            ['meeting_id' => '12345678910', 'meeting_uuid' => 'occurrence-two', 'start_time' => now()->subDay()->toIso8601String()],
        ]], 200);
        if (str_contains($request->url(), '/past_meetings/occurrence-one/participants')) return Http::response(['participants' => [
            ['registrant_id' => 'registrant-one', 'participant_user_id' => 'zoom-user-a', 'name' => 'Guest name', 'join_time' => '2026-10-26T10:00:00Z', 'leave_time' => '2026-10-26T10:02:00Z', 'duration' => 120],
            ['registrant_id' => 'registrant-one', 'participant_user_id' => 'zoom-user-b', 'name' => 'Guest name', 'join_time' => '2026-10-26T10:05:00Z', 'leave_time' => '2026-10-26T10:08:00Z', 'duration' => 180],
            ['registrant_id' => 'unknown-registrant', 'participant_user_id' => 'zoom-guest', 'name' => 'Unmatched guest', 'join_time' => '2026-10-26T10:10:00Z', 'leave_time' => '2026-10-26T10:11:00Z', 'duration' => 60],
        ]], 200);
        if (str_contains($request->url(), '/past_meetings/occurrence-two/participants')) return Http::response(['participants' => [
            ['participant_user_id' => 'fallback-user', 'name' => $student->pseudocode.' JSM', 'join_time' => '2026-11-02T10:00:00Z', 'leave_time' => '2026-11-02T10:02:00Z', 'duration' => 120],
        ]], 200);
        return Http::response([], 404);
    });

    $admin = zoomIntegrationUser('admin', 'zoom-admin-attendance@example.test');
    $batch = ZoomBatch::create(['name' => 'Attendance batch', 'created_by' => $admin->id]);
    ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'joined_at' => now(), 'created_by' => $admin->id]);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Weekly attendance', 'meeting_url' => 'https://zoom.us/j/main', 'scheduled_at' => now()->subDays(10), 'zoom_meeting_id' => '12345678910', 'zoom_meeting_type' => 8, 'registration_type' => 1]);
    StudentZoomAssignment::create(['student_id' => $student->id, 'zoom_session_id' => $session->id, 'zoom_url' => 'https://zoom.us/r/student', 'is_active' => true, 'registrant_id' => 'registrant-one', 'pseudocode_snapshot' => $student->pseudocode, 'registration_status' => 'registered', 'registered_at' => now(), 'registration_source' => 'zoom_api']);
    $endpoint = "/api/admin/zoom/batches/{$batch->id}/sessions/{$session->id}/attendance/sync";

    $this->actingAs($admin)->postJson($endpoint)->assertOk()->assertJsonPath('occurrences', 2)->assertJsonPath('matched', 3)->assertJsonPath('unmatched', 1);
    expect(ZoomAttendanceRecord::query()->where('zoom_session_id', $session->id)->count())->toBe(4)
        ->and(ZoomAttendanceRecord::query()->where('zoom_meeting_uuid', 'occurrence-one')->where('student_id', $student->id)->sum('duration_seconds'))->toBe(300)
        ->and(ZoomAttendanceRecord::query()->where('zoom_meeting_uuid', 'occurrence-two')->where('student_id', $student->id)->value('participant_pseudocode'))->toBe($student->pseudocode)
        ->and(ZoomAttendanceRecord::query()->where('match_status', 'unmatched')->value('student_id'))->toBeNull();

    $this->postJson($endpoint)->assertOk();
    expect(ZoomAttendanceRecord::query()->where('zoom_session_id', $session->id)->count())->toBe(4);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/past_meetings/occurrence-one/participants') && str_contains($request->url(), 'include_fields=registrant_id'));
});
