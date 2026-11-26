<?php

use App\Jobs\RunWhisperxAlignment;
use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ApprovedTranscriptWordMatcher;
use App\Services\BoundedWindowPlanner;
use App\Services\OpenAiWhisper1Executor;
use App\Services\SubtitleProcessingMethod;
use App\Services\SubtitleChunkOverlapDeduplicator;
use App\Services\WhisperxAlignmentExecutor;
use App\Services\WhisperxProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function subtitleMethodAdmin(): User
{
    return User::create(['name' => 'Subtitle admin', 'email' => 'subtitle-'.str()->uuid().'@example.test', 'password' => 'not-a-real-password', 'role' => 'admin', 'email_verified_at' => now()]);
}

function subtitleMethodSample(): array
{
    $category = Category::create(['name' => 'Subtitle '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Subtitle test', 'sort_order' => 1, 'duration_seconds' => 100]);
    $audio = 'ai-audio-preparation/subtitle-test/chunk-001.mp3';
    Storage::disk('local')->put($audio, 'fake audio for HTTP mock');
    $transcript = AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'gpt-transcribe', 'lesson_id' => $lesson->id,
        'timeline_offset_seconds' => 42.75, 'status' => 'succeeded', 'idempotency_key' => 'transcript-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'Word one.'),
        'result' => ['text' => 'Word one.', 'segments' => null, 'transcript_review' => ['status' => 'approved', 'language' => 'en', 'approved_text' => 'Word one.', 'approved_sha256' => hash('sha256', 'Word one.')], 'prepared_audio_path' => $audio],
    ]);
    return [$lesson, $transcript, $audio];
}

it('uses a persistent validated setting and defaults safely to standard WhisperX', function () {
    $admin = subtitleMethodAdmin();
    $this->actingAs($admin)->getJson('/api/admin/subtitle-processing-method')->assertOk()
        ->assertJsonPath('processing_method', SubtitleProcessingMethod::STANDARD)
        ->assertJsonPath('default_method', SubtitleProcessingMethod::STANDARD);
    $this->putJson('/api/admin/subtitle-processing-method', ['processing_method' => 'unsupported'])->assertUnprocessable();
    $this->putJson('/api/admin/subtitle-processing-method', ['processing_method' => SubtitleProcessingMethod::BOUNDED])
        ->assertOk()->assertJsonPath('processing_method', SubtitleProcessingMethod::BOUNDED);
    expect(SiteSetting::query()->where('key', SubtitleProcessingMethod::SETTING_KEY)->value('value'))->toBe(SubtitleProcessingMethod::BOUNDED);
});

it('plans overlapping windows only from matching timestamped transcript segments', function () {
    $plan = app(BoundedWindowPlanner::class)->plan('Go go now. Stay here. Go go now. Stay here.', [
        ['start' => 0, 'end' => 20, 'text' => 'Go go now.'],
        ['start' => 20, 'end' => 40, 'text' => 'Stay here.'],
        ['start' => 40, 'end' => 60, 'text' => 'Go go now.'],
        ['start' => 60, 'end' => 80, 'text' => 'Stay here.'],
    ]);
    expect($plan['anchor_source'])->toBe('timestamped_transcription_segments')
        ->and($plan['token_count'])->toBe(10)
        ->and($plan['windows'])->toHaveCount(3)
        ->and($plan['windows'][1]['token_start'])->toBeLessThan($plan['windows'][0]['token_end'])
        ->and($plan['windows'][1]['audio_start'])->toBeLessThan($plan['windows'][0]['audio_end']);
    expect(fn () => app(BoundedWindowPlanner::class)->plan('Go altered now. Stay here.', [
        ['start' => 0, 'end' => 20, 'text' => 'Go go now.'], ['start' => 20, 'end' => 40, 'text' => 'Stay here.'],
    ]))->toThrow(RuntimeException::class, 'do not match');
    expect(fn () => app(BoundedWindowPlanner::class)->plan('No anchors.', []))->toThrow(RuntimeException::class, 'unavailable');
});

it('preserves repeated source token positions and reports missing, added, ambiguous and zero-duration words', function () {
    $matcher = app(ApprovedTranscriptWordMatcher::class);
    $exact = $matcher->align('Go go now.', [
        ['word' => 'Go', 'start' => 0, 'end' => .2], ['word' => 'go', 'start' => .3, 'end' => .5], ['word' => 'now.', 'start' => .6, 'end' => 1],
    ], 1);
    expect(array_column($exact['words'], 'api_word_index'))->toBe([0, 1, 2])->and($exact['successfully_aligned_words'])->toBe(3);

    $partial = $matcher->align('one two three', [
        ['word' => 'one', 'start' => 0, 'end' => .1], ['word' => 'extra', 'start' => .15, 'end' => .2], ['word' => 'three', 'start' => .3, 'end' => .4],
    ], 1);
    expect($partial['missing_words'])->toContain(['source_token_index' => 1, 'word' => 'two', 'reason' => 'missing_api_word'])
        ->and($partial['added_api_words'][0]['word'])->toBe('extra');

    $zero = $matcher->align('word', [['word' => 'word', 'start' => 2, 'end' => 2]], 5);
    expect($zero['words'][0]['start'])->toBeNull()->and($zero['words'][0]['reason'])->toBe('zero_duration_timestamp')
        ->and($zero['missing_or_unreliable_words'])->toBe(1);
    $ambiguous = $matcher->align('very very good', [
        ['word' => 'very', 'start' => 0, 'end' => .2], ['word' => 'good', 'start' => .3, 'end' => .5],
    ], 1);
    expect($ambiguous['ambiguous_words'])->toHaveCount(2)->and($ambiguous['successfully_aligned_words'])->toBe(1);
    expect(app(SubtitleChunkOverlapDeduplicator::class)->duplicatePrefixLength('we are here again', 'here again we go'))->toBe(2);
});

it('falls back to standard WhisperX when bounded anchors are unavailable and records the chosen method', function () {
    Storage::fake('local');
    [$lesson, $transcript, $audio] = subtitleMethodSample();
    SubtitleProcessingMethod::save(SubtitleProcessingMethod::BOUNDED);
    config(['whisperx.enabled' => true, 'whisperx.script_path' => base_path('tools/whisperx-aligner/align_transcript.py'), 'whisperx.python_binary' => PHP_BINARY, 'whisperx.device' => 'cpu']);
    Queue::fake();
    $this->actingAs(subtitleMethodAdmin())->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', ['transcript_operation_id' => $transcript->id, 'audio_path' => $audio])->assertAccepted();
    $usage = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    SubtitleProcessingMethod::save(SubtitleProcessingMethod::OPENAI_WHISPER1);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    $processes->shouldReceive('run')->once()->withArgs(fn (array $arguments): bool => str_ends_with($arguments[1], 'align_transcript.py'))
        ->andReturnUsing(function (array $arguments): int {
            $output = $arguments[7];
            $words = [['text' => 'Word', 'start' => 1.0, 'end' => 1.5, 'score' => .9, 'status' => 'aligned'], ['text' => 'one.', 'start' => 1.5, 'end' => 2.0, 'score' => .9, 'status' => 'aligned']];
            file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.json', json_encode(['language' => 'en', 'audio_duration_seconds' => 5, 'words' => $words]));
            file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.srt', "1\n00:00:01,000 --> 00:00:02,000\nWord one.\n");
            file_put_contents($output.DIRECTORY_SEPARATOR.'alignment-report.json', json_encode(['total_words' => 2, 'successfully_aligned_words' => 2, 'missing_or_unreliable_words' => 0, 'errors' => [], 'audio_duration_seconds' => 5]));
            return 0;
        });
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();
    $job->handle(app(WhisperxAlignmentExecutor::class), app(OpenAiWhisper1Executor::class));
    $usage->refresh();
    expect($usage->status)->toBe('succeeded')->and($usage->result['processing_method'])->toBe(SubtitleProcessingMethod::BOUNDED)
        ->and($usage->result['effective_method'])->toBe(SubtitleProcessingMethod::STANDARD)
        ->and($usage->result['fallback_reason'])->toContain('fallback is required');
});

it('snapshots the selected Whisper-1 method and never invokes WhisperX for that operation', function () {
    Storage::fake('local');
    [$lesson, $transcript, $audio] = subtitleMethodSample();
    $integration = AiIntegration::query()->create(['provider' => 'openai', 'label' => 'OpenAI test', 'endpoint' => null, 'credentials' => ['api_key' => 'test-key'], 'is_enabled' => true]);
    AiServiceAssignment::query()->create(['service' => 'transcription', 'ai_integration_id' => $integration->id, 'model' => 'gpt-transcribe']);
    SubtitleProcessingMethod::save(SubtitleProcessingMethod::OPENAI_WHISPER1);
    config(['whisperx.enabled' => false]);
    Queue::fake();
    $this->actingAs(subtitleMethodAdmin())->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', [
        'transcript_operation_id' => $transcript->id, 'audio_path' => $audio,
    ])->assertAccepted();
    $usage = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    expect($usage->model)->toBe('whisper-1')->and($usage->result['processing_method'])->toBe(SubtitleProcessingMethod::OPENAI_WHISPER1)->and($usage->ai_integration_id)->toBe($integration->id);

    SubtitleProcessingMethod::save(SubtitleProcessingMethod::STANDARD);
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response([
        'text' => 'Word one.', 'duration' => 5, 'words' => [
            ['word' => 'Word', 'start' => 1, 'end' => 1.4], ['word' => 'one.', 'start' => 1.5, 'end' => 2],
        ], 'usage' => ['type' => 'duration', 'seconds' => 5],
    ], 200, ['x-request-id' => 'req-test-1'])]);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    $processes->shouldNotReceive('run');
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();
    $job->handle(app(WhisperxAlignmentExecutor::class), app(OpenAiWhisper1Executor::class));
    $usage->refresh();
    expect($usage->status)->toBe('succeeded')
        ->and($usage->result['processing_method'])->toBe(SubtitleProcessingMethod::OPENAI_WHISPER1)
        ->and($usage->result['outputs']['raw_response'])->toContain('whisper1-response.json')
        ->and($usage->result['request_reference'])->toBe('req-test-1')
        ->and($usage->result['successfully_aligned_words'])->toBe(2)
        ->and($usage->result['api_usage']['audio_minutes'])->toBeGreaterThan(0.08)
        ->and($usage->result['api_usage']['audio_minutes'])->toBeLessThan(0.09);
    $aligned = json_decode(Storage::disk('local')->get($usage->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    expect($aligned['words'][0]['start'])->toBe(43.75)->and($aligned['timeline_offset_seconds'])->toBe(42.75)->and($aligned['timestamp_scope'])->toBe('lesson');
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $field = fn (string $name): mixed => collect($data)->first(fn (array $part): bool => ($part['name'] ?? null) === $name)['contents'] ?? null;
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/audio/transcriptions')
            && $field('model') === 'whisper-1'
            && $field('response_format') === 'verbose_json'
            && $field('timestamp_granularities[]') === 'word'
            && $request->hasFile('file');
    });
});

it('fails rather than succeeding when Whisper-1 returns zero-duration required words', function () {
    Storage::fake('local');
    [$lesson, $transcript, $audio] = subtitleMethodSample();
    $integration = AiIntegration::query()->create(['provider' => 'openai', 'label' => 'OpenAI test', 'credentials' => ['api_key' => 'test-key'], 'is_enabled' => true]);
    AiServiceAssignment::query()->create(['service' => 'transcription', 'ai_integration_id' => $integration->id, 'model' => 'gpt-transcribe']);
    SubtitleProcessingMethod::save(SubtitleProcessingMethod::OPENAI_WHISPER1);
    Queue::fake();
    $this->actingAs(subtitleMethodAdmin())->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', ['transcript_operation_id' => $transcript->id, 'audio_path' => $audio])->assertAccepted();
    $usage = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Word one.', 'duration' => 5, 'words' => [
        ['word' => 'Word', 'start' => 1, 'end' => 1], ['word' => 'one.', 'start' => 1.5, 'end' => 2],
    ]], 200)]);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();
    $job->handle(app(WhisperxAlignmentExecutor::class), app(OpenAiWhisper1Executor::class));
    $usage->refresh();
    expect($usage->status)->toBe('failed')->and($usage->error_code)->toBe('WordTimestampsIncomplete')
        ->and($usage->result['missing_or_unreliable_words'])->toBe(1)
        ->and(Storage::disk('local')->exists($usage->result['outputs']['raw_response']))->toBeTrue();
});

it('omits verified repeated words from overlapping Whisper-1 chunk subtitles without changing approved text', function () {
    Storage::fake('local');
    $category = Category::create(['name' => 'Overlap '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Overlap test', 'sort_order' => 1, 'duration_seconds' => 10]);
    $previousAudio = 'ai-audio-preparation/overlap/chunk-001.mp3';
    $currentAudio = 'ai-audio-preparation/overlap/chunk-002.mp3';
    Storage::disk('local')->put($previousAudio, 'previous audio');
    Storage::disk('local')->put($currentAudio, 'current audio');
    AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'gpt-transcribe', 'lesson_id' => $lesson->id,
        'timeline_offset_seconds' => 0, 'audio_minutes' => .025, 'status' => 'succeeded',
        'idempotency_key' => 'prep-77-chunk-1', 'request_fingerprint' => hash('sha256', 'previous'),
        'result' => ['text' => 'we are here again.', 'transcript_review' => ['status' => 'approved', 'language' => 'en', 'approved_text' => 'we are here again.']],
    ]);
    $current = AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'gpt-transcribe', 'lesson_id' => $lesson->id,
        'timeline_offset_seconds' => 1, 'status' => 'succeeded', 'idempotency_key' => 'prep-77-chunk-2',
        'request_fingerprint' => hash('sha256', 'here again go.'),
        'result' => ['text' => 'here again go.', 'prepared_audio_path' => $currentAudio,
            'transcript_review' => ['status' => 'approved', 'language' => 'en', 'approved_text' => 'here again go.', 'approved_sha256' => hash('sha256', 'here again go.')]],
    ]);
    $integration = AiIntegration::query()->create(['provider' => 'openai', 'label' => 'OpenAI overlap', 'credentials' => ['api_key' => 'test-key'], 'is_enabled' => true]);
    AiServiceAssignment::query()->create(['service' => 'transcription', 'ai_integration_id' => $integration->id, 'model' => 'gpt-transcribe']);
    SubtitleProcessingMethod::save(SubtitleProcessingMethod::OPENAI_WHISPER1);
    Queue::fake();
    $this->actingAs(subtitleMethodAdmin())->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', ['transcript_operation_id' => $current->id, 'audio_path' => $currentAudio])->assertAccepted();
    $operation = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'here again go.', 'duration' => 2, 'words' => [
        ['word' => 'here', 'start' => .1, 'end' => .3], ['word' => 'again', 'start' => .4, 'end' => .6], ['word' => 'go.', 'start' => .7, 'end' => .9],
    ]], 200)]);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    $processes->shouldNotReceive('run');
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    Queue::pushed(RunWhisperxAlignment::class)->first()->handle(app(WhisperxAlignmentExecutor::class), app(OpenAiWhisper1Executor::class));
    $operation->refresh();
    $aligned = json_decode(Storage::disk('local')->get($operation->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    $srt = Storage::disk('local')->get($operation->result['outputs']['srt']);
    expect($operation->status)->toBe('succeeded')
        ->and($operation->result['overlap_duplicate_word_count'])->toBe(2)
        ->and($operation->result['successfully_aligned_words'])->toBe(3)
        ->and($aligned['words'][0]['text'])->toBe('here')
        ->and($aligned['words'][0]['status'])->toBe('overlap_duplicate')
        ->and($aligned['words'][0]['start'])->toBe(1.1)
        ->and($srt)->toContain('go.')
        ->and($srt)->not->toContain('here again');
});
