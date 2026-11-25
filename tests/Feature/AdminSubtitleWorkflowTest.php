<?php

use App\Jobs\PrepareLessonAudioForSubtitles;
use App\Jobs\RunWhisperxAlignment;
use App\Services\AiOperationService;
use App\Services\SubtitleSrtCueService;
use App\Support\AiProvider;
use App\Support\AiProviderRegistry;
use App\Support\SubtitleCueTranslationProvider;
use App\Services\LessonAudioPreparationException;
use App\Services\LessonAudioPreparationService;
use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function subtitleWorkflowLesson(): Lesson
{
    $category = Category::create(['name' => 'Subtitle workflow '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    return Lesson::create(['category_id' => $category->id, 'title' => 'Workflow lesson', 'sort_order' => 1, 'duration_seconds' => 120]);
}

function subtitleWorkflowUser(string $role): User
{
    return User::create(['name' => 'Subtitle workflow '.$role, 'email' => 'subtitle-workflow-'.str()->uuid().'@example.test', 'password' => 'StrongPass123!', 'role' => $role]);
}

function subtitleWorkflowAssignment(string $service, ?string $targetLanguage = null): AiIntegration
{
    $integration = AiIntegration::query()->create([
        'provider' => 'openai', 'label' => 'Workflow '.$service, 'endpoint' => 'https://api.openai.com/v1',
        'credentials' => ['api_key' => 'test-key'], 'is_enabled' => true,
    ]);
    AiServiceAssignment::query()->create([
        'service' => $service, 'ai_integration_id' => $integration->id,
        'model' => $service === 'transcription' ? 'whisper-1' : 'gpt-4o-mini',
        'target_language' => $targetLanguage,
    ]);
    return $integration;
}

it('queues private audio preparation once and keeps workflow actions admin-only', function () {
    Storage::fake('local');
    config(['queue.default' => 'database', 'whisperx.connection' => 'whisperx', 'whisperx.queue' => 'whisperx-alignment']);
    config(['whisperx.enabled' => false, 'whisperx.python_binary' => 'C:/missing-python/python.exe']);
    $lesson = subtitleWorkflowLesson();
    Storage::disk('local')->put('lesson-videos/subtitle-workflow.mp4', 'private video fixture');
    $lesson->update(['video_path' => 'lesson-videos/subtitle-workflow.mp4']);
    subtitleWorkflowAssignment('transcription');
    $admin = subtitleWorkflowUser('admin');
    $student = subtitleWorkflowUser('student');
    Queue::fake();

    $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow/transcribe')
        ->assertAccepted()->assertJsonPath('status', 'queued');
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow/transcribe')
        ->assertOk()->assertJsonPath('already_submitted', true);
    Queue::assertPushed(PrepareLessonAudioForSubtitles::class, 1);
    Queue::assertNotPushed(RunWhisperxAlignment::class);
    $job = Queue::pushed(PrepareLessonAudioForSubtitles::class)->first();
    expect($job->connection)->toBe('whisperx')->and($job->queue)->toBe('whisperx-alignment')
        ->and($job->timeout)->toBe(3600);
    expect(AiOperationUsage::query()->where('service', 'audio_preparation')->count())->toBe(1);

    AiOperationUsage::query()->where('service', 'audio_preparation')->update(['updated_at' => now()->subSeconds(90)]);
    $this->getJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow')
        ->assertOk()->assertJsonPath('preparations.0.queue.worker_stalled', true)
        ->assertJsonPath('preparations.0.queue.queue', 'whisperx-alignment')
        ->assertJsonPath('preparations.0.queue.worker_command', 'php artisan queue:work whisperx --queue=whisperx-alignment --sleep=3 --tries=2 --timeout=7200');

    $this->actingAs($student)->getJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow')->assertForbidden();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow/transcribe')->assertForbidden();
});

it('moves preparation through processing to completed without calling a provider', function () {
    Storage::fake('local');
    $lesson = subtitleWorkflowLesson();
    $operation = AiOperationUsage::query()->create([
        'service' => 'audio_preparation', 'model' => 'ffmpeg-local', 'lesson_id' => $lesson->id,
        'status' => 'queued', 'idempotency_key' => 'audio-prep-success-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'video'), 'result' => [],
    ]);
    Storage::disk('local')->put('ai-audio-preparation/test/chunk-001.mp3', 'prepared audio');
    $preparation = Mockery::mock(LessonAudioPreparationService::class);
    $preparation->shouldReceive('prepare')->once()->withArgs(function (Lesson $input) use ($lesson, $operation): bool {
        expect($input->is($lesson))->toBeTrue()->and($operation->fresh()->status)->toBe('processing');
        return true;
    })->andReturn([
        'workspace' => 'ai-audio-preparation/test', 'chunks' => [[
            'path' => 'ai-audio-preparation/test/chunk-001.mp3', 'offset_seconds' => 0.0, 'duration_seconds' => 30.0,
        ]],
    ]);
    $operations = Mockery::mock(AiOperationService::class);
    $operations->shouldReceive('queueTranscription')->once()->withArgs(fn (...$args) => $args[1] === 'prep-'.$operation->id.'-chunk-1'
        && $args[5] === null)->andReturn(['usage_id' => 45, 'status' => 'queued']);
    $this->app->instance(LessonAudioPreparationService::class, $preparation);
    $this->app->instance(AiOperationService::class, $operations);

    (new PrepareLessonAudioForSubtitles($operation->id))->handle($preparation, $operations);

    expect($operation->fresh()->status)->toBe('succeeded')
        ->and($operation->fresh()->result['preparation']['transcription_operations'][0]['operation_id'])->toBe(45)
        ->and($operation->fresh()->result['processing_completed_at'])->not->toBeEmpty();
});

it('records preparation failures and marks the operation failed after retries are exhausted', function () {
    $lesson = subtitleWorkflowLesson();
    $operation = AiOperationUsage::query()->create([
        'service' => 'audio_preparation', 'model' => 'ffmpeg-local', 'lesson_id' => $lesson->id,
        'status' => 'queued', 'idempotency_key' => 'audio-prep-failure-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'video'), 'result' => [],
    ]);
    $preparation = Mockery::mock(LessonAudioPreparationService::class);
    $exception = new LessonAudioPreparationException('ffmpeg unavailable');
    $preparation->shouldReceive('prepare')->once()->andThrow($exception);
    $operations = Mockery::mock(AiOperationService::class);
    $job = new PrepareLessonAudioForSubtitles($operation->id);

    try {
        $job->handle($preparation, $operations);
        test()->fail('Expected the audio preparation failure.');
    } catch (LessonAudioPreparationException) {
        expect($operation->fresh()->status)->toBe('queued')
            ->and($operation->fresh()->error_code)->toBe('LessonAudioPreparationException');
    }
    $job->failed($exception);

    expect($operation->fresh()->status)->toBe('failed')
        ->and($operation->fresh()->error_message)->toContain('after retry attempts');
});

it('saves and approves the edited transcript snapshot and requires its approved English subtitles before Filipino translation', function () {
    config(['queue.default' => 'database']);
    $lesson = subtitleWorkflowLesson();
    $admin = subtitleWorkflowUser('admin');
    $transcript = AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'whisper-1', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'workflow-transcript-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'Original.'), 'result' => ['text' => 'Original.'],
    ]);
    $this->actingAs($admin)->getJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow')
        ->assertOk()->assertJsonPath('transcripts.0.text', 'Original.');
    $this->actingAs($admin)->putJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/draft', ['text' => 'Reviewed wording!'])
        ->assertOk()->assertJsonPath('text', 'Reviewed wording!');
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/approve', ['language' => 'en'])
        ->assertOk()->assertJsonPath('review_status', 'approved');
    expect($transcript->fresh()->result['transcript_review']['approved_text'])->toBe('Reviewed wording!');

    subtitleWorkflowAssignment('translation', 'Filipino');
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate', [
        'source_alignment_operation_id' => 999, 'source_english_srt_version_id' => (string) str()->uuid(),
    ])
        ->assertUnprocessable()->assertJsonValidationErrors('translation');

    $englishSrt = "1\n00:00:01,000 --> 00:00:02,000\nReviewed wording!\n";
    $englishPath = 'approved-subtitles/'.$lesson->id.'/alignment/en/source.srt';
    Storage::disk('local')->put($englishPath, $englishSrt);
    $englishVersionId = (string) str()->uuid();
    $alignment = AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'workflow-alignment-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'alignment'), 'result' => [
            'transcript_operation_id' => $transcript->id,
            'transcript_sha256' => hash('sha256', 'Reviewed wording!'),
            'approval_statuses' => ['en' => 'approved'],
            'subtitle_versions' => ['en' => ['current_approved_version_id' => $englishVersionId, 'versions' => [[
                'version_id' => $englishVersionId, 'path' => $englishPath, 'sha256' => hash('sha256', $englishSrt),
            ]]]],
        ],
    ]);
    Queue::fake();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate', [
        'source_alignment_operation_id' => $alignment->id, 'source_english_srt_version_id' => $englishVersionId,
    ])
        ->assertAccepted()->assertJsonPath('status', 'queued');
    $translation = AiOperationUsage::query()->where('service', 'translation')->firstOrFail();
    expect($translation->result['target_language'])->toBe('Filipino')
        ->and($translation->result['source_transcript_operation_id'])->toBe($transcript->id)
        ->and($translation->result['source_transcript_sha256'])->toBe(hash('sha256', 'Reviewed wording!'));
});

it('translates each approved English SRT cue and approves Filipino using identical timestamps without an upload', function () {
    Storage::fake('local');
    config(['queue.default' => 'database']);
    $lesson = subtitleWorkflowLesson();
    $admin = subtitleWorkflowUser('admin');
    subtitleWorkflowAssignment('translation', 'Filipino');
    $englishText = 'In the beginning. God created the heavens.';
    $transcript = AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'whisper-1', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'cue-transcript-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', $englishText), 'result' => ['text' => $englishText,
            'transcript_review' => ['status' => 'approved', 'language' => 'en', 'approved_text' => $englishText]],
    ]);
    $englishSrt = "1\n00:00:01,125 --> 00:00:02,250\nIn the beginning.\n\n2\n00:00:02,300 --> 00:00:03,875\nGod created the heavens.\n";
    $englishPath = 'approved-subtitles/'.$lesson->id.'/71/en/approved.srt';
    Storage::disk('local')->put($englishPath, $englishSrt);
    $versionId = (string) str()->uuid();
    $alignment = AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'cue-alignment-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'cue alignment'), 'result' => [
            'transcript_operation_id' => $transcript->id, 'transcript_sha256' => hash('sha256', $englishText),
            'approval_statuses' => ['en' => 'approved'], 'subtitle_versions' => ['en' => [
                'current_approved_version_id' => $versionId,
                'versions' => [['version_id' => $versionId, 'path' => $englishPath, 'sha256' => hash('sha256', $englishSrt)]],
            ]],
        ],
    ]);
    Queue::fake();
    $admin->refresh();
    $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate', [
        'source_alignment_operation_id' => $alignment->id, 'source_english_srt_version_id' => $versionId,
    ])->assertAccepted()->assertJsonPath('target_language', 'Filipino');
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate', [
        'source_alignment_operation_id' => $alignment->id, 'source_english_srt_version_id' => $versionId,
    ])->assertOk()->assertJsonPath('already_submitted', true);
    Queue::assertPushed(App\Services\RunAiOperation::class, 1);
    $translation = AiOperationUsage::query()->where('service', 'translation')->firstOrFail();
    expect($translation->result['source_english_srt_sha256'])->toBe(hash('sha256', $englishSrt))
        ->and($translation->result['source_alignment_operation_id'])->toBe($alignment->id);

    $provider = new class implements AiProvider, SubtitleCueTranslationProvider {
        public function key(): string { return 'openai'; }
        public function name(): string { return 'test'; }
        public function capabilities(): array { return ['translation']; }
        public function credentialFields(): array { return []; }
        public function supportedModels(string $service): array { return ['gpt-4o-mini']; }
        public function testConnection(array $configuration, array $credentials): array { return []; }
        public function transcribe(array $configuration, array $input): array { throw new RuntimeException('Not used in this test.'); }
        public function translate(array $configuration, array $input): array { throw new RuntimeException('Cue translation should be used.'); }
        public function translateSubtitleCues(array $configuration, array $input): array {
            return ['cues' => array_map(fn ($cue) => ['index' => $cue['index'], 'text' => $cue['index'] === 1 ? 'Nang pasimula.' : 'Nilikha ng Diyos ang kalangitan.'], $input['cues'])];
        }
    };
    $registry = new AiProviderRegistry(); $registry->register($provider);
    app()->instance(AiProviderRegistry::class, $registry);
    $job = Queue::pushed(App\Services\RunAiOperation::class)->first();
    expect($job->timeout)->toBe(600);
    $payload = json_decode(Illuminate\Support\Facades\Crypt::decryptString($job->encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
    app(AiOperationService::class)->runQueued($translation->id, $payload);
    $translation->refresh();
    expect($translation->status)->toBe('succeeded')
        ->and($translation->result['subtitle_draft']['cues'][0]['start'])->toBe('00:00:01,125')
        ->and($translation->result['subtitle_draft']['cues'][1]['end'])->toBe('00:00:03,875');
    $draft = Storage::disk('local')->get($translation->result['subtitle_draft']['srt_path']);
    expect($draft)->toContain("00:00:01,125 --> 00:00:02,250\nNang pasimula.")
        ->and($draft)->toContain("00:00:02,300 --> 00:00:03,875\nNilikha ng Diyos ang kalangitan.");

    $this->putJson('/api/admin/lessons/'.$lesson->id.'/translations/'.$translation->id.'/draft', ['cues' => [
        ['index' => 1, 'text' => 'Nang pasimula!'], ['index' => 2, 'text' => 'Nilikha ng Diyos ang kalangitan.'],
    ]])->assertOk();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$translation->id.'/fil/approve')->assertCreated();
    $approved = app(App\Services\ApprovedSubtitleService::class)->approvedVersion($lesson, $translation->fresh(), 'fil');
    $approvedSrt = Storage::disk('local')->get($approved['path']);
    app(SubtitleSrtCueService::class)->assertSameTiming(app(SubtitleSrtCueService::class)->parse($englishSrt), app(SubtitleSrtCueService::class)->parse($approvedSrt));
    expect($approvedSrt)->toContain('Nang pasimula!')->and(Storage::disk('local')->allFiles('subtitle-drafts'))->toContain($translation->result['subtitle_draft']['srt_path']);
});
