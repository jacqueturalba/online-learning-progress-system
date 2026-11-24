<?php

use App\Jobs\RunWhisperxAlignment;
use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use App\Services\WhisperxAlignmentExecutor;
use App\Services\WhisperxAlignmentException;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function whisperxTestLesson(): Lesson
{
    $category = Category::create(['name' => 'WhisperX '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    return Lesson::create(['category_id' => $category->id, 'title' => 'WhisperX review lesson', 'sort_order' => 1, 'duration_seconds' => 90]);
}

function whisperxAdmin(string $role = 'admin'): User
{
    return User::create([
        'name' => 'WhisperX reviewer',
        'email' => 'whisperx-'.str()->uuid().'@example.test',
        'password' => 'not-a-real-password',
        'role' => $role,
        'email_verified_at' => now(),
    ]);
}

function whisperxTranscript(Lesson $lesson, string $text = 'Word one.', float $offset = 0): AiOperationUsage
{
    return AiOperationUsage::query()->create([
        'service' => 'transcription',
        'model' => 'gpt-transcribe',
        'lesson_id' => $lesson->id,
        'timeline_offset_seconds' => $offset,
        'status' => 'succeeded',
        'idempotency_key' => 'whisperx-transcript-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', $text),
        'result' => ['text' => $text, 'timestamp_status' => 'text_only', 'segments' => null],
    ]);
}

function whisperxPreparedAudio(): string
{
    Storage::fake('local');
    config(['ai_media.temp_directory' => 'ai-audio-preparation']);
    $relative = 'ai-audio-preparation/test-workspace/chunk-001.mp3';
    Storage::disk('local')->put($relative, 'prepared private audio fixture');
    return $relative;
}

function queueWhisperxForTest(Lesson $lesson, AiOperationUsage $transcript, string $audioPath, User $admin): AiOperationUsage
{
    config(['whisperx.enabled' => true]);
    $response = test()->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/approve', ['language' => 'en']);
    $response->assertOk();
    $queued = test()->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', [
        'transcript_operation_id' => $transcript->id,
        'audio_path' => $audioPath,
    ]);
    $queued->assertAccepted();
    return AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
}

function fakeWhisperxSuccess(WhisperxProcessRunner $processes, string $transcript, float $duration = 5.0): void
{
    $tokens = preg_split('/\s+/u', trim($transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = [];
    $cursor = 1.0;
    foreach ($tokens as $token) {
        $words[] = ['text' => $token, 'start' => $cursor, 'end' => $cursor + 0.5, 'score' => 0.9, 'status' => 'aligned'];
        $cursor += 0.5;
    }
    $processes->shouldReceive('run')->once()->withArgs(function (array $arguments, int $timeout): bool {
        expect($arguments)->toHaveCount(12)
            ->and($arguments[2])->toBe('--audio')
            ->and($arguments[4])->toBe('--transcript')
            ->and($arguments[6])->toBe('--output')
            ->and(array_slice($arguments, 8))->toBe(['--language', 'en', '--device', 'cpu'])
            ->and($timeout)->toBe(7200);
        return true;
    })->andReturnUsing(function (array $arguments) use ($words, $transcript, $duration): int {
        $transcriptPath = $arguments[5];
        expect(file_get_contents($transcriptPath))->toBe($transcript);
        $output = $arguments[7];
        file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.json', json_encode(['language' => 'en', 'audio_duration_seconds' => $duration, 'words' => $words], JSON_THROW_ON_ERROR));
        file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.srt', "1\n00:00:01,000 --> 00:00:02,000\n{$transcript}\n");
        file_put_contents($output.DIRECTORY_SEPARATOR.'alignment-report.json', json_encode([
            'total_words' => count($words),
            'successfully_aligned_words' => count($words),
            'missing_or_unreliable_words' => 0,
            'audio_duration_seconds' => $duration,
            'processing_device' => 'cpu',
            'model_used' => 'WAV2VEC2_ASR_BASE_960H',
            'warnings' => [],
            'errors' => [],
        ], JSON_THROW_ON_ERROR));
        return 0;
    });
}

beforeEach(function () {
    config([
        'whisperx.enabled' => true,
        'whisperx.python_binary' => PHP_BINARY,
        'whisperx.script_path' => base_path('tools/whisperx-aligner/align_transcript.py'),
        'whisperx.device' => 'cpu',
        'whisperx.timeout' => 7200,
        'whisperx.connection' => 'whisperx',
        'whisperx.queue' => 'whisperx-alignment',
        'queue.connections.whisperx.driver' => 'database',
    ]);
});

it('requires an admin-approved English transcript and queues once on the dedicated database queue', function () {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson);
    $audio = whisperxPreparedAudio();
    $admin = whisperxAdmin();
    Queue::fake();

    $response = $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', [
        'transcript_operation_id' => $transcript->id,
        'audio_path' => $audio,
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors('alignment');
    expect(AiOperationUsage::query()->where('service', 'whisperx_alignment')->count())->toBe(0);

    $approve = $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/approve', ['language' => 'en']);
    $approve->assertOk()->assertJsonPath('review_status', 'approved');
    $payload = ['transcript_operation_id' => $transcript->id, 'audio_path' => $audio];
    $first = $this->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', $payload)->assertAccepted();
    $second = $this->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', $payload)->assertOk();
    $operation = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();

    expect($operation->status)->toBe('queued')
        ->and($operation->result['approval_status'])->toBe('pending_admin_review')
        ->and($second->json('already_submitted'))->toBeTrue();
    Queue::assertPushed(RunWhisperxAlignment::class, 1);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();
    expect($job->connection)->toBe('whisperx')->and($job->queue)->toBe('whisperx-alignment');
});

it('passes an argument array, validates outputs, applies the stored offset once, and keeps SRT pending admin review', function () {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson, 'Word one.', 42.75);
    $audio = whisperxPreparedAudio();
    $admin = whisperxAdmin();
    Queue::fake();
    $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    fakeWhisperxSuccess($processes, 'Word one.');
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();

    $job->handle(app(WhisperxAlignmentExecutor::class));
    $usage->refresh();

    expect($usage->status)->toBe('succeeded')
        ->and($usage->result['approval_status'])->toBe('pending_admin_review')
        ->and($usage->result['successfully_aligned_words'])->toBe(2)
        ->and($usage->result['timeline_offset_seconds'])->toBe(42.75)
        ->and($usage->result['processing_started_at'])->not->toBeNull()
        ->and($usage->result['processing_completed_at'])->not->toBeNull();
    $alignedPath = $usage->result['outputs']['aligned_json'];
    $aligned = json_decode(Storage::disk('local')->get($alignedPath), true, flags: JSON_THROW_ON_ERROR);
    expect($aligned['words'][0]['start'])->toBe(43.75)
        ->and($aligned['words'][1]['text'])->toBe('one.')
        ->and($aligned['timestamp_scope'])->toBe('lesson');
    $srt = Storage::disk('local')->get($usage->result['outputs']['srt']);
    expect($srt)->toContain('00:00:43,750 --> 00:00:44,750')->toContain('Word one.');
    Storage::disk('local')->assertMissing('whisperx-alignments/'.$lesson->id.'/'.$usage->id.'/input-transcript.txt');

    $this->actingAs($admin)->get('/api/admin/whisperx-alignments/'.$usage->id.'/srt')->assertNotFound();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$usage->id.'/en/approve')->assertCreated();
    $this->get('/api/admin/whisperx-alignments/'.$usage->id.'/srt')->assertOk()->assertSee('Word one.');
    $this->actingAs(whisperxAdmin('student'))->get('/api/admin/whisperx-alignments/'.$usage->id.'/srt')->assertForbidden();
});

it('records missing Python or runner configuration as safe failures without starting a process', function () {
    foreach ([
        ['whisperx.python_binary', 'whisperx-python-that-does-not-exist'],
        ['whisperx.script_path', base_path('missing-whisperx-runner.py')],
    ] as [$key, $value]) {
        $lesson = whisperxTestLesson();
        $transcript = whisperxTranscript($lesson);
        $audio = whisperxPreparedAudio();
        $admin = whisperxAdmin();
        Queue::fake();
        $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
        config([$key => $value]);
        $processes = Mockery::mock(WhisperxProcessRunner::class);
        $processes->shouldNotReceive('run');
        $this->app->instance(WhisperxProcessRunner::class, $processes);
        $job = Queue::pushed(RunWhisperxAlignment::class)->first();

        try {
            $job->handle(app(WhisperxAlignmentExecutor::class));
            test()->fail('Expected a safe configuration failure.');
        } catch (WhisperxAlignmentException $exception) {
            expect($exception->safeCode)->toBe($key === 'whisperx.python_binary' ? 'PythonUnavailable' : 'RunnerUnavailable');
            $job->failed($exception);
        }
        expect($usage->fresh()->status)->toBe('failed');
        config([$key => $key === 'whisperx.python_binary' ? PHP_BINARY : base_path('tools/whisperx-aligner/align_transcript.py')]);
        Queue::fake();
    }
});

it('handles non-zero process exits and timeouts without retaining incomplete output', function (string $failure, string $safeCode) {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson);
    $audio = whisperxPreparedAudio();
    $admin = whisperxAdmin();
    Queue::fake();
    $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    if ($failure === 'exit') {
        $processes->shouldReceive('run')->once()->andReturn(9);
    } else {
        $processes->shouldReceive('run')->once()->andThrow(new WhisperxAlignmentException('ProcessTimedOut', 'WhisperX exceeded its configured processing timeout.'));
    }
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();

    try {
        $job->handle(app(WhisperxAlignmentExecutor::class));
        test()->fail('Expected the mocked process failure.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe($safeCode);
        $job->failed($exception);
    }

    expect($usage->fresh()->status)->toBe('failed')
        ->and($usage->fresh()->error_code)->toBe($safeCode);
    Storage::disk('local')->assertMissing('whisperx-alignments/'.$lesson->id.'/'.$usage->id.'/aligned.srt');
})->with([
    'non-zero exit' => ['exit', 'ProcessFailed'],
    'timeout' => ['timeout', 'ProcessTimedOut'],
]);

it('rejects missing and malformed result files and leaves no reviewable partial SRT', function (string $mode) {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson);
    $audio = whisperxPreparedAudio();
    $admin = whisperxAdmin();
    Queue::fake();
    $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
    $processes = Mockery::mock(WhisperxProcessRunner::class);
    $processes->shouldReceive('run')->once()->andReturnUsing(function (array $arguments) use ($mode): int {
        $output = $arguments[7];
        if ($mode === 'invalid') {
            file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.json', '{bad json');
            file_put_contents($output.DIRECTORY_SEPARATOR.'aligned.srt', "1\n00:00:00,000 --> 00:00:01,000\ntext\n");
            file_put_contents($output.DIRECTORY_SEPARATOR.'alignment-report.json', '{}');
        }
        return 0;
    });
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();

    try {
        $job->handle(app(WhisperxAlignmentExecutor::class));
        test()->fail('Expected output validation to fail.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe($mode === 'missing' ? 'OutputMissing' : 'OutputInvalid');
        $job->failed($exception);
    }

    expect($usage->fresh()->status)->toBe('failed');
    Storage::disk('local')->assertMissing('whisperx-alignments/'.$lesson->id.'/'.$usage->id.'/aligned.srt');
})->with(['missing', 'invalid']);
