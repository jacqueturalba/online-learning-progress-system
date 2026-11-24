<?php

use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use App\Services\ApprovedSubtitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function studentSubtitlePlaybackCategory(): Category
{
    return Category::create([
        'name' => 'Student subtitle playback '.str()->uuid(),
        'sort_order' => 1,
        'is_active' => true,
        'unlock_all_lessons' => false,
    ]);
}

function studentSubtitlePlaybackLesson(Category $category, int $sortOrder = 1): Lesson
{
    return Lesson::create([
        'category_id' => $category->id,
        'title' => 'Subtitle playback lesson '.$sortOrder,
        'sort_order' => $sortOrder,
        'duration_seconds' => 90,
        'is_published' => true,
    ]);
}

function studentSubtitlePlaybackUser(string $role = 'student'): User
{
    return User::create([
        'name' => 'Subtitle playback '.$role,
        'email' => 'subtitle-playback-'.$role.'-'.str()->uuid().'@example.test',
        'password' => 'not-a-real-password',
        'role' => $role,
        'email_verified_at' => now(),
    ]);
}

function approveStudentEnglishTrack(Lesson $lesson, string $srt): array
{
    $path = 'whisperx-alignments/'.$lesson->id.'/student-track/aligned.srt';
    Storage::disk('local')->put($path, $srt);
    $operation = AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'student-track-en-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', $srt), 'result' => [
            'approval_status' => 'pending_admin_review', 'outputs' => ['srt' => $path],
        ],
    ]);
    $version = app(ApprovedSubtitleService::class)->approve($lesson, $operation, 'en', 1, null, $srt);

    return [$operation, $version, $path];
}

function approveStudentFilipinoTrack(Lesson $lesson, string $srt, string $text): array
{
    $path = 'subtitle-drafts/'.$lesson->id.'/student-track/fil.srt';
    Storage::disk('local')->put($path, $srt);
    $operation = AiOperationUsage::query()->create([
        'service' => 'translation', 'model' => 'gpt-4o-mini', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'student-track-fil-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', $text), 'result' => [
            'text' => $text,
            'target_language' => 'Filipino',
            'subtitle_draft' => ['text' => $text, 'srt_path' => $path, 'srt_sha256' => hash('sha256', $srt)],
        ],
    ]);
    $version = app(ApprovedSubtitleService::class)->approve($lesson, $operation, 'fil', 1);

    return [$operation, $version, $path];
}

it('serves the current approved English SRT as timed WebVTT without changing the source file', function () {
    Storage::fake('local');
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());
    $student = studentSubtitlePlaybackUser();
    $srt = "1\n00:00:01,250 --> 00:00:03,750\nHello, world.\n";
    [, , $sourcePath] = approveStudentEnglishTrack($lesson, $srt);
    $sourceHash = hash('sha256', Storage::disk('local')->get($sourcePath));

    $tracks = $this->actingAs($student)->getJson('/api/lessons/'.$lesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.language', 'en')
        ->assertJsonPath('tracks.0.label', 'English')->assertJsonMissingPath('tracks.0.path')->json('tracks');
    $response = $this->get($tracks[0]['url']);

    $response->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'inline; filename="lesson-'.$lesson->id.'-en.vtt"')
        ->assertSee('WEBVTT', false)->assertSee('00:00:01.250 --> 00:00:03.750', false)
        ->assertSee('Hello, world.', false)->assertDontSee('whisperx-alignments', false);
    expect(hash('sha256', Storage::disk('local')->get($sourcePath)))->toBe($sourceHash);
});

it('serves an approved Filipino track independently', function () {
    Storage::fake('local');
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());
    $student = studentSubtitlePlaybackUser();
    $srt = "1\n00:00:02,000 --> 00:00:04,000\nKumusta, mundo.\n";
    approveStudentFilipinoTrack($lesson, $srt, 'Kumusta, mundo.');

    $tracks = $this->actingAs($student)->getJson('/api/lessons/'.$lesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.language', 'fil')->json('tracks');
    $this->get($tracks[0]['url'])->assertOk()->assertSee('00:00:02.000 --> 00:00:04.000', false)
        ->assertSee('Kumusta, mundo.', false);
});

it('offers both approved languages as separate tracks', function () {
    Storage::fake('local');
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());
    $student = studentSubtitlePlaybackUser();
    approveStudentEnglishTrack($lesson, "1\n00:00:01,000 --> 00:00:02,000\nHello.\n");
    approveStudentFilipinoTrack($lesson, "1\n00:00:01,000 --> 00:00:02,000\nKumusta.\n", 'Kumusta.');

    $this->actingAs($student)->getJson('/api/lessons/'.$lesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(2, 'tracks')->assertJsonPath('tracks.0.language', 'en')
        ->assertJsonPath('tracks.1.language', 'fil');
});

it('hides pending-review subtitle operations from students', function () {
    Storage::fake('local');
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());
    $student = studentSubtitlePlaybackUser();
    $pendingPath = 'whisperx-alignments/'.$lesson->id.'/pending/aligned.srt';
    Storage::disk('local')->put($pendingPath, "1\n00:00:01,000 --> 00:00:02,000\nPending.\n");
    AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'student-track-pending-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'pending'),
        'result' => ['approval_status' => 'pending_admin_review', 'outputs' => ['srt' => $pendingPath]],
    ]);

    $this->actingAs($student)->getJson('/api/lessons/'.$lesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(0, 'tracks');
    $this->get('/api/lessons/'.$lesson->id.'/subtitles/en')->assertNotFound();
});

it('returns no tracks when no subtitle has been approved', function () {
    Storage::fake('local');
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());

    $this->actingAs(studentSubtitlePlaybackUser())->getJson('/api/lessons/'.$lesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(0, 'tracks');
});

it('does not let a student retrieve a locked lesson track or download the private SRT', function () {
    Storage::fake('local');
    $category = studentSubtitlePlaybackCategory();
    $accessibleLesson = studentSubtitlePlaybackLesson($category, 1);
    $lockedLesson = studentSubtitlePlaybackLesson($category, 2);
    $student = studentSubtitlePlaybackUser();
    $admin = studentSubtitlePlaybackUser('admin');
    $srt = "1\n00:00:00,500 --> 00:00:01,500\nPrivate cue.\n";
    [$operation, $version] = approveStudentEnglishTrack($lockedLesson, $srt);

    $this->actingAs($student)->getJson('/api/lessons/'.$accessibleLesson->id.'/subtitles')
        ->assertOk()->assertJsonCount(0, 'tracks');
    $this->get('/api/lessons/'.$lockedLesson->id.'/subtitles/en')->assertNotFound();
    $downloadUrl = route('admin.subtitles.download', [$lockedLesson->id, 'en', $operation->id, $version['version_id']], false);
    $this->get($downloadUrl)->assertForbidden();
    $this->actingAs($admin)->get($downloadUrl)->assertOk()->assertSee('Private cue.');
});

it('requires student authentication for subtitle track discovery and delivery', function () {
    $lesson = studentSubtitlePlaybackLesson(studentSubtitlePlaybackCategory());

    $this->getJson('/api/lessons/'.$lesson->id.'/subtitles')->assertUnauthorized();
    $this->getJson('/api/lessons/'.$lesson->id.'/subtitles/en')->assertUnauthorized();
});
