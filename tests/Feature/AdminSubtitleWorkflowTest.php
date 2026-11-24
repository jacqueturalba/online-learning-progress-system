<?php

use App\Jobs\PrepareLessonAudioForSubtitles;
use App\Services\AiOperationService;
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
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate')
        ->assertUnprocessable()->assertJsonValidationErrors('translation');

    AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx-3.8.6', 'lesson_id' => $lesson->id,
        'status' => 'succeeded', 'idempotency_key' => 'workflow-alignment-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'alignment'), 'result' => [
            'transcript_operation_id' => $transcript->id,
            'transcript_sha256' => hash('sha256', 'Reviewed wording!'),
            'approval_statuses' => ['en' => 'approved'],
            'subtitle_versions' => ['en' => ['current_approved_version_id' => (string) str()->uuid()]],
        ],
    ]);
    Queue::fake();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/translate')
        ->assertAccepted()->assertJsonPath('status', 'queued');
    $translation = AiOperationUsage::query()->where('service', 'translation')->firstOrFail();
    expect($translation->result['target_language'])->toBe('Filipino')
        ->and($translation->result['source_transcript_operation_id'])->toBe($transcript->id)
        ->and($translation->result['source_transcript_sha256'])->toBe(hash('sha256', 'Reviewed wording!'));
});
