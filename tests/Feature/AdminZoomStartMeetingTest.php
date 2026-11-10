<?php

use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomIntegrationSetting;
use App\Models\ZoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function startMeetingTestUser(string $role, string $email): User
{
    return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => $role]);
}

function startMeetingTestBatch(User $creator, ?string $hostId = 'zoom-host-42', bool $withMeeting = true): ZoomBatch
{
    $batch = ZoomBatch::create(['name' => 'Start meeting batch', 'created_by' => $creator->id]);
    if ($withMeeting) {
        ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Start test meeting', 'meeting_url' => 'https://zoom.us/j/123456',
            'scheduled_at' => now(), 'zoom_meeting_id' => '123456', 'zoom_host_id' => $hostId, 'meeting_status' => 'active']);
    }
    return $batch;
}

function fakeStartMeetingZoom(?string $hostEmail = 'host@example.test', ?string $actualHostId = 'zoom-host-42'): void
{
    ZoomIntegrationSetting::create(['account_id' => 'account-id', 'client_id' => 'client-id', 'client_secret' => 'client-secret']);
    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'private-start-token', 'expires_in' => 3600], 200),
        'https://api.zoom.us/v2/meetings/123456' => Http::response(['id' => '123456', 'host_id' => $actualHostId, 'start_url' => 'https://zoom.us/s/private-host-start-link'], 200),
        'https://api.zoom.us/v2/users/zoom-host-42' => Http::response(['id' => 'zoom-host-42', 'email' => $hostEmail], 200),
    ]);
}

it('allows the assigned Zoom host by matching Zoom user id and email to the authenticated internal admin', function () {
    fakeStartMeetingZoom('assigned-host@example.test');
    $creator = startMeetingTestUser('admin', 'batch-creator@example.test');
    $hostAdmin = startMeetingTestUser('admin', 'assigned-host@example.test');
    $batch = startMeetingTestBatch($creator);

    $this->actingAs($hostAdmin)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertRedirect('https://zoom.us/s/private-host-start-link');
});

it('allows a super admin to start any batch meeting without confusing Zoom host ids with internal user ids', function () {
    fakeStartMeetingZoom();
    $creator = startMeetingTestUser('admin', 'any-batch-creator@example.test');
    $superAdmin = startMeetingTestUser('super_admin', 'zoom-super-admin@example.test');
    $batch = startMeetingTestBatch($creator);

    $this->actingAs($superAdmin)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertRedirect('https://zoom.us/s/private-host-start-link');
});

it('allows the admin assigned as the batch creator to start its meeting', function () {
    fakeStartMeetingZoom();
    $assignedAdmin = startMeetingTestUser('admin', 'batch-assignee@example.test');
    $batch = startMeetingTestBatch($assignedAdmin);

    $this->actingAs($assignedAdmin)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertRedirect('https://zoom.us/s/private-host-start-link');
});

it('blocks unauthorized admins and students from receiving private host links', function () {
    fakeStartMeetingZoom();
    $creator = startMeetingTestUser('admin', 'protected-batch-creator@example.test');
    $unauthorizedAdmin = startMeetingTestUser('admin', 'unassigned-admin@example.test');
    $student = startMeetingTestUser('student', 'student-start-attempt@example.test');
    $batch = startMeetingTestBatch($creator);

    $this->actingAs($unauthorizedAdmin)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertForbidden()
        ->assertDontSee('https://zoom.us/s/private-host-start-link');
    $this->actingAs($student)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertForbidden()
        ->assertDontSee('https://zoom.us/s/private-host-start-link');
});

it('returns a clear configuration error for batches without a meeting or assigned host', function () {
    $admin = startMeetingTestUser('admin', 'meeting-config-admin@example.test');
    fakeStartMeetingZoom();
    $withoutMeeting = startMeetingTestBatch($admin, withMeeting: false);
    $this->actingAs($admin)->postJson("/api/admin/zoom/batches/{$withoutMeeting->id}/start")->assertStatus(422)
        ->assertJsonPath('message', 'This batch does not have a Zoom meeting configured.');

    $withoutHost = startMeetingTestBatch($admin, hostId: null);
    $this->postJson("/api/admin/zoom/batches/{$withoutHost->id}/start")->assertStatus(422)
        ->assertJsonPath('message', 'This meeting does not have a valid assigned Zoom host. Reconfigure the meeting host before starting it.');
});

it('rejects stale host assignments and non-Zoom start URLs', function () {
    fakeStartMeetingZoom(actualHostId: 'different-zoom-host');
    $admin = startMeetingTestUser('super_admin', 'host-mismatch-superadmin@example.test');
    $batch = startMeetingTestBatch($admin);
    $this->actingAs($admin)->postJson("/api/admin/zoom/batches/{$batch->id}/start")->assertStatus(422)
        ->assertJsonPath('message', 'The Zoom meeting host no longer matches this batch’s assigned host. Reconfigure the meeting host before starting it.');
});
