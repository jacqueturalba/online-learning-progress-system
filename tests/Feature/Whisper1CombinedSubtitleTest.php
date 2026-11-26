<?php

use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Models\Category;
use App\Models\Lesson;
use App\Services\AiOperationService;
use App\Services\RunAiOperation;
use App\Services\SubtitleProcessingMethod;
use App\Services\Whisper1CombinedSubtitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function combinedWhisperSample(): array
{
    $category = Category::create(['name' => 'Whisper-1 combined test', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Combined subtitles', 'sort_order' => 1, 'duration_seconds' => 60]);
    $integration = AiIntegration::query()->create([
        'provider' => 'openai', 'label' => 'OpenAI combined test', 'endpoint' => 'https://api.openai.com/v1',
        'credentials' => ['api_key' => 'test-key'], 'is_enabled' => true,
    ]);
    return [$lesson, $integration];
}

it('uses one Whisper-1 verbose transcription request to create the transcript and word timestamp draft', function () {
    Storage::fake('local');
    [$lesson, $integration] = combinedWhisperSample();
    $audio = 'ai-audio-preparation/combined/chunk-001.mp3';
    Storage::disk('local')->put($audio, 'fake local audio');
    $transcript = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'whisper-1',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => 12.5, 'status' => 'queued',
        'idempotency_key' => 'prep-777-chunk-1', 'request_fingerprint' => hash('sha256', 'request'),
        'request_reference' => 'transcription-reference',
    ]);
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response([
        'text' => 'Here we go.', 'duration' => 4,
        'words' => [
            ['word' => 'Here', 'start' => 0.2, 'end' => 0.5],
            ['word' => 'we', 'start' => 0.6, 'end' => 0.8],
            ['word' => 'go.', 'start' => 0.9, 'end' => 1.2],
        ], 'usage' => ['type' => 'duration', 'seconds' => 4],
    ], 200, ['x-request-id' => 'req-combined-test'])]);

    $payload = [
        'service' => 'transcription', 'lesson_id' => $lesson->id,
        'audio_path' => Storage::disk('local')->path($audio), 'prepared_audio_path' => $audio,
        'language' => 'en', 'subtitle_processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
    ];
    $job = new RunAiOperation($transcript->id, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
    $job->handle(app(AiOperationService::class), app(Whisper1CombinedSubtitleService::class));

    $transcript->refresh();
    $alignment = AiOperationUsage::query()->where('service', 'whisperx_alignment')->where('lesson_id', $lesson->id)->firstOrFail();
    $aligned = json_decode(Storage::disk('local')->get($alignment->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    expect($transcript->status)->toBe('succeeded')
        ->and($transcript->result['text'])->toBe('Here we go.')
        ->and($transcript->result['transcript_source'])->toBe('openai_whisper1_response')
        ->and(app(\App\Services\WhisperxAlignmentService::class)->approvedTranscriptText($transcript))->toBe('Here we go.')
        ->and(array_key_exists('raw_response', $transcript->result))->toBeFalse()
        ->and($alignment->status)->toBe('succeeded')
        ->and($alignment->result['successfully_aligned_words'])->toBe(3)
        ->and($aligned['words'][0]['text'])->toBe('Here')
        ->and($aligned['words'][0]['start'])->toBe(12.7)
        ->and($aligned['timeline_offset_seconds'])->toBe(12.5)
        ->and(Storage::disk('local')->get($alignment->result['outputs']['raw_response']))->toContain('Here we go.');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data());
        $field = fn (string $name): mixed => $parts->first(fn (array $part) => ($part['name'] ?? null) === $name)['contents'] ?? null;
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/audio/transcriptions')
            && $field('model') === 'whisper-1'
            && $field('response_format') === 'verbose_json'
            && $field('timestamp_granularities[]') === 'word'
            && $request->hasFile('file');
    });
});

it('keeps incomplete Whisper-1 timing reviewable and omits invalid words without inventing timestamps', function () {
    Storage::fake('local');
    [$lesson, $integration] = combinedWhisperSample();
    $transcript = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'whisper-1',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => 0, 'status' => 'succeeded',
        'idempotency_key' => 'prep-778-chunk-1', 'request_fingerprint' => hash('sha256', 'request'),
        'request_reference' => 'req-partial',
        'result' => [
            'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'text' => 'First missing last.', 'duration_seconds' => 3,
            'words' => [
                ['word' => 'First', 'start' => 0.1, 'end' => 0.4],
                ['word' => 'missing', 'start' => null, 'end' => null],
                ['word' => 'zero', 'start' => 1.0, 'end' => 1.0],
                ['word' => 'last.', 'start' => 2.2, 'end' => 2.8],
            ],
            'raw_response' => json_encode(['text' => 'First missing last.']),
            'request_duration_ms' => 1200, 'usage' => ['audio_minutes' => 0.05],
        ],
    ]);

    $alignment = app(Whisper1CombinedSubtitleService::class)->createFromTranscript($transcript);
    $srt = Storage::disk('local')->get($alignment->result['outputs']['srt']);
    $aligned = json_decode(Storage::disk('local')->get($alignment->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    expect($alignment->status)->toBe('partial')
        ->and($alignment->result['review_available'])->toBeTrue()
        ->and($alignment->result['timestamps_complete'])->toBeFalse()
        ->and($alignment->result['missing_words'][0]['word'])->toBe('missing')
        ->and($alignment->result['missing_words'][1]['reason'])->toBe('invalid_timestamp')
        ->and($aligned['words'][1]['start'])->toBeNull()
        ->and($aligned['words'][2]['end'])->toBeNull()
        ->and($srt)->toContain('First')
        ->and($srt)->toContain('last.')
        ->and($srt)->not->toContain('missing')
        ->and($srt)->not->toContain('zero');
});

it('deduplicates only confirmed overlap token positions and keeps later repeated words', function () {
    Storage::fake('local');
    [$lesson, $integration] = combinedWhisperSample();
    AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'whisper-1',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => 0, 'status' => 'succeeded', 'audio_minutes' => 5 / 60,
        'idempotency_key' => 'prep-779-chunk-1', 'request_fingerprint' => hash('sha256', 'previous'),
        'result' => ['text' => 'we are here again', 'duration_seconds' => 5],
    ]);
    $current = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'whisper-1',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => 4, 'status' => 'succeeded',
        'idempotency_key' => 'prep-779-chunk-2', 'request_fingerprint' => hash('sha256', 'here again go'),
        'result' => [
            'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'text' => 'here again go', 'duration_seconds' => 3,
            'words' => [
                ['word' => 'here', 'start' => 0.1, 'end' => 0.3],
                ['word' => 'again', 'start' => 0.4, 'end' => 0.6],
                ['word' => 'go', 'start' => 0.7, 'end' => 0.9],
            ], 'raw_response' => '{"text":"here again go"}', 'request_duration_ms' => 900,
        ],
    ]);
    $alignment = app(Whisper1CombinedSubtitleService::class)->createFromTranscript($current);
    $aligned = json_decode(Storage::disk('local')->get($alignment->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    $srt = Storage::disk('local')->get($alignment->result['outputs']['srt']);

    expect($alignment->result['overlap_duplicate_word_count'])->toBe(2)
        ->and($aligned['words'][0]['status'])->toBe('overlap_duplicate')
        ->and($aligned['words'][1]['status'])->toBe('overlap_duplicate')
        ->and($aligned['words'][2]['text'])->toBe('go')
        ->and($srt)->not->toContain('here again')
        ->and($srt)->toContain('go');
});

it('reports out-of-order and out-of-range word timestamps as partial instead of correcting them', function () {
    Storage::fake('local');
    [$lesson, $integration] = combinedWhisperSample();
    $transcript = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration->id, 'service' => 'transcription', 'model' => 'whisper-1',
        'lesson_id' => $lesson->id, 'timeline_offset_seconds' => 0, 'status' => 'succeeded',
        'idempotency_key' => 'prep-780-chunk-1', 'request_fingerprint' => hash('sha256', 'request'),
        'result' => [
            'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'text' => 'First second third.', 'duration_seconds' => 3,
            'words' => [
                ['word' => 'First', 'start' => 1.2, 'end' => 1.5],
                ['word' => 'second', 'start' => 0.4, 'end' => 0.8],
                ['word' => 'third.', 'start' => 3.1, 'end' => 3.5],
            ], 'raw_response' => '{"text":"First second third."}',
        ],
    ]);

    $alignment = app(Whisper1CombinedSubtitleService::class)->createFromTranscript($transcript);
    $aligned = json_decode(Storage::disk('local')->get($alignment->result['outputs']['aligned_json']), true, flags: JSON_THROW_ON_ERROR);
    expect($alignment->status)->toBe('partial')
        ->and($alignment->result['timestamps_ordered'])->toBeFalse()
        ->and($alignment->result['timestamp_order_violations'])->toHaveCount(1)
        ->and($alignment->result['missing_or_unreliable_words'])->toBe(2)
        ->and($aligned['words'][1]['start'])->toBeNull()
        ->and($aligned['words'][2]['start'])->toBeNull();
});

it('queues combined Whisper-1 work on the long-running subtitle queue with compatible timeout margins', function () {
    Storage::fake('local');
    [$lesson, $integration] = combinedWhisperSample();
    AiServiceAssignment::query()->create(['service' => 'transcription', 'ai_integration_id' => $integration->id, 'model' => 'whisper-1']);
    Storage::disk('local')->makeDirectory('ai-audio-preparation/queue');
    $audio = 'ai-audio-preparation/queue/chunk.mp3';
    Storage::disk('local')->put($audio, 'fake queue audio');
    Queue::fake();

    $queued = app(AiOperationService::class)->queueTranscription(
        Storage::disk('local')->path($audio), 'combined-whisper1-queue-1', $lesson->id,
        'en', 0, null, SubtitleProcessingMethod::OPENAI_WHISPER1,
    );
    $job = Queue::pushed(RunAiOperation::class)->first();
    expect($queued['status'])->toBe('queued')
        ->and($job->connection)->toBe(config('whisperx.connection'))
        ->and($job->queue)->toBe(config('whisperx.queue'))
        ->and($job->timeout)->toBeGreaterThan(config('openai.whisper_http_timeout'))
        ->and(config('queue.connections.whisperx.retry_after'))->toBeGreaterThan($job->timeout);
});
