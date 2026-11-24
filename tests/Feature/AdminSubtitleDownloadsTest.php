<?php

use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function subtitleDownloadLesson(): Lesson
{
    $category = Category::create(['name' => 'Subtitles '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    return Lesson::create(['category_id' => $category->id, 'title' => 'Subtitle test lesson', 'sort_order' => 1, 'duration_seconds' => 90]);
}

function subtitleDownloadUser(string $role): User
{
    return User::create([
        'name' => 'Subtitle '.$role,
        'email' => 'subtitle-'.$role.'-'.str()->uuid().'@example.test',
        'password' => 'not-a-real-password',
        'role' => $role,
        'email_verified_at' => now(),
    ]);
}

it('keeps English and Filipino SRT versions private, immutable, and admin-only', function () {
    Storage::fake('local');
    $lesson = subtitleDownloadLesson();
    $admin = subtitleDownloadUser('admin');
    $student = subtitleDownloadUser('student');

    $englishSrt = "1\n00:00:01,000 --> 00:00:02,000\nHello there.\n";
    $englishPath = 'whisperx-alignments/'.$lesson->id.'/81/aligned.srt';
    Storage::disk('local')->put($englishPath, $englishSrt);
    $english = AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'subtitle-english-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'english'), 'result' => [
            'approval_status' => 'pending_admin_review', 'outputs' => ['srt' => $englishPath],
        ],
    ]);

    $this->actingAs($admin)->getJson('/api/admin/lessons/'.$lesson->id.'/subtitles')->assertOk()->assertJsonCount(0, 'subtitles');
    $englishApproval = $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$english->id.'/en/approve')->assertCreated();
    $englishVersion = $englishApproval->json('version_id');
    $englishUrl = $englishApproval->json('download_url');
    $this->get($englishUrl)->assertOk()->assertHeader('Content-Type', 'application/x-subrip; charset=UTF-8')->assertSee('Hello there.');
    $duplicateEnglish = $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$english->id.'/en/approve')->assertCreated();
    expect($duplicateEnglish->json('version_id'))->toBe($englishVersion);

    $filipino = AiOperationUsage::query()->create([
        'service' => 'translation', 'model' => 'gpt-4o-mini', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'subtitle-filipino-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'filipino'), 'result' => ['text' => 'Kumusta.', 'target_language' => 'Filipino'],
    ]);
    $filipinoSrt = "1\n00:00:01,000 --> 00:00:02,000\nKumusta.\n";
    $filipinoApproval = $this->post('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$filipino->id.'/fil/approve', [
        'srt_file' => UploadedFile::fake()->createWithContent('approved-filipino.srt', $filipinoSrt),
    ])->assertCreated();
    $filipinoUrl = $filipinoApproval->json('download_url');
    $this->get($filipinoUrl)->assertOk()->assertSee('Kumusta.');

    $newEnglishSrt = "1\n00:00:01,000 --> 00:00:02,000\nHello there, revised.\n";
    $newVersion = $this->post('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$english->id.'/en/approve', [
        'srt_file' => UploadedFile::fake()->createWithContent('revised-english.srt', $newEnglishSrt),
    ])->assertCreated()->json('version_id');
    expect($newVersion)->not->toBe($englishVersion);
    $this->get($englishUrl)->assertOk()->assertSee('Hello there.')->assertDontSee('revised');
    $this->get('/api/admin/lessons/'.$lesson->id.'/subtitles')->assertOk()->assertJsonCount(3, 'subtitles');

    $this->actingAs($student)->get($englishUrl)->assertForbidden();
    $this->get($filipinoUrl)->assertForbidden();
    $this->get('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$filipino->id.'/fil/approve')->assertMethodNotAllowed();
});

it('requires a timed Filipino SRT and does not expose drafts for download', function () {
    Storage::fake('local');
    $lesson = subtitleDownloadLesson();
    $admin = subtitleDownloadUser('admin');
    $translation = AiOperationUsage::query()->create([
        'service' => 'translation', 'model' => 'gpt-4o-mini', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'subtitle-filipino-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'filipino'), 'result' => ['text' => 'Hola.', 'target_language' => 'Spanish'],
    ]);

    $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$translation->id.'/fil/approve')
        ->assertUnprocessable()->assertJsonValidationErrors('subtitle');
    $this->withHeader('Accept', 'application/json')->post('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$translation->id.'/fil/approve', [
        'srt_file' => UploadedFile::fake()->createWithContent('spanish.srt', "1\n00:00:00,000 --> 00:00:01,000\nHola.\n"),
    ])->assertUnprocessable();
    $this->get('/api/admin/lessons/'.$lesson->id.'/subtitles/fil/'.$translation->id.'/not-a-version/download')->assertNotFound();
});

it('resolves an older approved transcript to its exact prepared chunk by stored request fingerprint', function () {
    Storage::fake('local');
    config(['ai_media.temp_directory' => 'ai-audio-preparation']);
    config(['whisperx.enabled' => true]);
    config(['whisperx.connection' => 'whisperx', 'whisperx.queue' => 'whisperx-alignment']);
    config(['queue.connections.whisperx.driver' => 'database']);
    $lesson = subtitleDownloadLesson();
    $audioPath = 'ai-audio-preparation/legacy-workspace/chunk-001.mp3';
    Storage::disk('local')->put($audioPath, 'legacy prepared chunk bytes');
    $audioHash = hash('sha256', 'legacy prepared chunk bytes');
    $integration = AiIntegration::query()->create(['provider' => 'openai', 'label' => 'Legacy', 'credentials' => [], 'is_enabled' => true]);
    $offset = 0.0;
    $baseFingerprint = hash('sha256', json_encode([
        'service' => 'transcription', 'lesson_id' => $lesson->id, 'language' => 'en',
        'audio_sha256' => $audioHash, 'timeline_offset_seconds' => $offset,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $requestFingerprint = hash('sha256', implode('|', [$baseFingerprint, $integration->id, 'gpt-transcribe', '']));
    $transcript = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'gpt-transcribe',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => $offset, 'status' => 'succeeded',
        'idempotency_key' => 'subtitle-legacy-transcript-'.str()->uuid(), 'request_fingerprint' => $requestFingerprint,
        'result' => [
            'text' => 'Hello there.', 'transcript_review' => ['status' => 'approved', 'language' => 'en'],
        ],
    ]);
    Queue::fake();

    $response = $this->actingAs(subtitleDownloadUser('admin'))->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', [
        'transcript_operation_id' => $transcript->id,
    ]);

    $response->assertAccepted();
    $alignment = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    $payload = Illuminate\Support\Facades\Crypt::decryptString(Queue::pushed(App\Jobs\RunWhisperxAlignment::class)->first()->encryptedPayload);
    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['audio_relative_path'])->toBe($audioPath);
});
