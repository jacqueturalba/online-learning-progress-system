<?php

use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Models\Category;
use App\Models\Lesson;
use App\Services\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Services\RunAiOperation;

uses(RefreshDatabase::class);

function chunkTranscriptionLesson(): Lesson
{
    $category = Category::create(['name' => 'Chunk test '.uniqid(), 'sort_order' => 1, 'is_active' => true]);
    return Lesson::create(['category_id' => $category->id, 'title' => 'Single chunk test', 'sort_order' => 1, 'duration_seconds' => 300]);
}

function configureChunkTranscription(): void
{
    $account = AiIntegration::query()->create([
        'provider' => 'openai', 'label' => 'Local transcription test', 'endpoint' => 'https://api.openai.com/v1',
        'credentials' => ['api_key' => 'sk-test-not-used'], 'is_enabled' => true,
    ]);
    AiServiceAssignment::query()->create([
        'service' => 'transcription', 'ai_integration_id' => $account->id, 'model' => 'gpt-transcribe',
    ]);
}

function createPreparedChunk(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-chunk-test-'.str()->uuid();
    mkdir($root.DIRECTORY_SEPARATOR.'ai-audio-preparation'.DIRECTORY_SEPARATOR.'test-workspace', 0700, true);
    config(['filesystems.disks.local.root' => $root]);
    Storage::forgetDisk('local');
    config(['ai_media.temp_directory' => 'ai-audio-preparation', 'ai_media.max_chunk_bytes' => 20 * 1024 * 1024]);
    $relative = 'ai-audio-preparation/test-workspace/chunk-001.mp3';
    Storage::disk('local')->put($relative, 'test audio bytes');
    return Storage::disk('local')->path($relative);
}

afterEach(function () {
    $root = config('filesystems.disks.local.root');
    if (!is_string($root) || !str_starts_with($root, sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-chunk-test-') || !is_dir($root)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($root);
});

it('queues exactly one confirmed chunk asynchronously and persists lesson timeline metadata', function () {
    config(['queue.default' => 'database']);
    configureChunkTranscription();
    $lesson = chunkTranscriptionLesson();
    $path = createPreparedChunk();
    Queue::fake();
    Http::preventStrayRequests();

    $exit = Artisan::call('ai:transcribe-chunk', [
        'lesson' => $lesson->id, 'audio_path' => $path, 'offset' => '42.750', '--language' => 'en', '--confirm' => true,
    ]);
    $output = Artisan::output();

    $usage = AiOperationUsage::query()->where('service', 'transcription')->firstOrFail();
    expect($exit)->toBe(0)
        ->and($usage->lesson_id)->toBe($lesson->id)
        ->and($usage->model)->toBe('gpt-transcribe')
        ->and((float) $usage->timeline_offset_seconds)->toBe(42.75)
        ->and($usage->status)->toBe('queued')
        ->and($output)->toContain('One transcription operation was queued')
        ->and($output)->toContain('Operation ID: '.$usage->id);
    Queue::assertPushed(RunAiOperation::class, 1);
    expect(Queue::pushed(RunAiOperation::class)->first()->queue)->toBe('ai-transcription-test');
    Http::assertNothingSent();
});

it('requires explicit confirmation and rejects the synchronous queue driver', function () {
    configureChunkTranscription();
    $lesson = chunkTranscriptionLesson();
    $path = createPreparedChunk();
    Queue::fake();
    Http::preventStrayRequests();

    config(['queue.default' => 'database']);
    expect(Artisan::call('ai:transcribe-chunk', ['lesson' => $lesson->id, 'audio_path' => $path, 'offset' => '0']))->toBe(1);
    expect(Artisan::output())->toContain('Re-run with --confirm');

    config(['queue.default' => 'sync']);
    expect(Artisan::call('ai:transcribe-chunk', ['lesson' => $lesson->id, 'audio_path' => $path, 'offset' => '0', '--confirm' => true]))->toBe(1);
    expect(Artisan::output())->toContain('sync queue driver is not allowed')
        ->and(AiOperationUsage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('refuses an idempotency key already queued without submitting a duplicate', function () {
    config(['queue.default' => 'database']);
    configureChunkTranscription();
    $lesson = chunkTranscriptionLesson();
    $path = createPreparedChunk();
    Queue::fake();
    Http::preventStrayRequests();
    $arguments = ['lesson' => $lesson->id, 'audio_path' => $path, 'offset' => '5', '--confirm' => true];

    expect(Artisan::call('ai:transcribe-chunk', $arguments))->toBe(0);
    expect(Artisan::call('ai:transcribe-chunk', $arguments))->toBe(1);
    expect(Artisan::output())->toContain('already submitted')
        ->and(AiOperationUsage::query()->count())->toBe(1);
    Queue::assertPushed(RunAiOperation::class, 1);
    Http::assertNothingSent();
});

it('does not invent timestamps for text-only or malformed transcription segments', function () {
    Http::preventStrayRequests();
    Http::fakeSequence('https://api.openai.com/v1/audio/transcriptions')
        ->push(['text' => 'Text only.'], 200)
        ->push(['text' => 'Malformed timing.', 'segments' => [['end' => 2, 'text' => 'Malformed timing.']]], 200);
    $audio = tempnam(sys_get_temp_dir(), 'ai-chunk-audio-');
    file_put_contents($audio, 'controlled fake audio fixture');

    try {
        $provider = app(OpenAiProvider::class);
        $input = ['credentials' => ['api_key' => 'sk-test-not-used'], 'model' => 'gpt-transcribe', 'audio_path' => $audio, 'language' => 'en'];
        $textOnly = $provider->transcribe(['endpoint' => 'https://api.openai.com/v1'], $input);
        $malformed = $provider->transcribe(['endpoint' => 'https://api.openai.com/v1'], $input);
    } finally {
        @unlink($audio);
    }

    expect($textOnly['segments'])->toBeNull()
        ->and($textOnly['timestamp_status'])->toBe('text_only')
        ->and($malformed['segments'])->toBeNull()
        ->and($malformed['timestamp_status'])->toBe('invalid_segments');
});

it('retrieves the encrypted transcript and applies only the stored chunk timeline offset', function () {
    $lesson = chunkTranscriptionLesson();
    $usage = AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'gpt-transcribe', 'lesson_id' => $lesson->id,
        'timeline_offset_seconds' => 50, 'status' => 'succeeded', 'idempotency_key' => 'result-retrieval-01',
        'request_fingerprint' => str_repeat('b', 64),
        'result' => ['text' => 'A sample sentence.', 'segments' => [['start' => 1.25, 'end' => 2.5, 'text' => 'A sample sentence.']], 'timestamp_status' => 'timestamped'],
    ]);

    expect(Artisan::call('ai:transcribe-result', ['operation' => $usage->id]))->toBe(0)
        ->and(Artisan::output())->toContain('A sample sentence.')
        ->toContain('[51.250 - 52.500] A sample sentence.')
        ->toContain('Timestamp status: timestamped');
});
