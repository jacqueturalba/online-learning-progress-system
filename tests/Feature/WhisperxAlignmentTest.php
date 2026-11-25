<?php

use App\Jobs\RunWhisperxAlignment;
use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use App\Services\WhisperxAlignmentExecutor;
use App\Services\WhisperxAlignmentException;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxAlignmentReconciler;
use App\Services\WhisperxProcessRunner;
use App\Services\SymfonyWhisperxProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    return AiOperationUsage::query()->where('service', 'whisperx_alignment')->latest('id')->firstOrFail();
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
    $processes->shouldReceive('run')->once()->withArgs(function (array $arguments, int $timeout, ?callable $heartbeat = null): bool {
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
    expect($job->timeout)->toBe((int) config('whisperx.job_timeout'))
        ->and((int) config('queue.connections.whisperx.retry_after'))->toBeGreaterThan($job->timeout)
        ->and($operation->result['queue_token'])->not->toBeEmpty();
});

it('runs short processes successfully and terminates timed out processes', function () {
    $runner = new SymfonyWhisperxProcessRunner();
    $heartbeats = 0;
    $processIdentity = null;
    $exitCode = $runner->run([PHP_BINARY, '-r', 'usleep(250000);'], 3, function (?int $pid = null, ?array $identity = null) use (&$heartbeats, &$processIdentity): void {
        $heartbeats++;
        $processIdentity = $identity;
    });
    expect($exitCode)->toBe(0)->and($heartbeats)->toBeGreaterThan(0);
    expect($processIdentity['process_id'] ?? null)->toBeGreaterThan(1)
        ->and($processIdentity['creation_ticks'] ?? $processIdentity['start_ticks'] ?? null)->not->toBeNull();
    if (DIRECTORY_SEPARATOR !== '\\') {
        expect($processIdentity['process_group_id'] ?? null)->toBe($processIdentity['process_id'])
            ->and($processIdentity['session_id'] ?? null)->toBe($processIdentity['process_id']);
    }

    $marker = tempnam(sys_get_temp_dir(), 'whisperx-timeout-');
    expect(unlink($marker))->toBeTrue();
    expect(file_exists($marker))->toBeFalse('Unexpected orphan marker at '.$marker);
    $code = '$marker = '.var_export($marker, true).'; usleep(1800000); file_put_contents($marker, "orphan");';
    try {
        $runner->run([PHP_BINARY, '-r', $code], 1);
        test()->fail('Expected the fake process to time out.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessTimedOut');
    }
    usleep(1_000_000);
    expect(file_exists($marker))->toBeFalse('Unexpected orphan marker at '.$marker);
});

it('retries the Linux setsid transition until the actual Symfony process is isolated', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux setsid transition regression test did not run: Linux setsid and POSIX signals are required.');
    }

    $runner = new class extends SymfonyWhisperxProcessRunner {
        public int $simulatedTransitionReads = 0;
        private bool $simulateFirstRead = true;

        protected function linuxProcessIdentity(int $pid): ?array
        {
            $identity = parent::linuxProcessIdentity($pid);
            if ($identity !== null && $this->simulateFirstRead) {
                $this->simulateFirstRead = false;
                $this->simulatedTransitionReads++;
                $identity['process_group_id'] = max(2, $pid - 1);
                $identity['session_id'] = max(2, $pid - 2);
            }
            return $identity;
        }
    };
    $acceptedIdentity = null;
    $exitCode = $runner->run([PHP_BINARY, '-r', 'usleep(150000);'], 3,
        function (?int $pid, ?array $identity) use (&$acceptedIdentity): void { $acceptedIdentity = $identity; });

    expect($exitCode)->toBe(0)
        ->and($runner->simulatedTransitionReads)->toBe(1)
        ->and($acceptedIdentity['process_group_id'] ?? null)->toBe($acceptedIdentity['process_id'] ?? null)
        ->and($acceptedIdentity['session_id'] ?? null)->toBe($acceptedIdentity['process_id'] ?? null);
});

it('reports a Linux process that exits before process-group verification', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux early-exit regression test did not run: Linux setsid and POSIX signals are required.');
    }
    $runner = new class extends SymfonyWhisperxProcessRunner {
        protected function linuxProcessIdentity(int $pid): ?array
        {
            // Make the short-lived child exit after the liveness check but
            // before /proc can provide a verified isolated identity.
            usleep(120_000);
            return parent::linuxProcessIdentity($pid);
        }
    };

    try {
        $runner->run([PHP_BINARY, '-r', 'usleep(10_000);'], 3);
        test()->fail('Expected the child to exit before isolation was verified.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessExitedBeforeIsolation');
    }
});

it('distinguishes unreadable Linux process identity from a group transition timeout', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux unreadable-identity regression test did not run: Linux setsid and POSIX signals are required.');
    }
    $runner = new class extends SymfonyWhisperxProcessRunner {
        protected function linuxProcessIdentity(int $pid): ?array { return null; }
    };
    $startedAt = microtime(true);
    try {
        $runner->run([PHP_BINARY, '-r', 'usleep(5_000_000);'], 5);
        test()->fail('Expected unreadable process identity to fail closed.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessIdentityUnavailable');
    }
    expect(microtime(true) - $startedAt)->toBeLessThan(2.0);
});

it('rejects a Linux PID whose start-time identity changes during startup polling', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux startup PID-reuse regression test did not run: Linux setsid and POSIX signals are required.');
    }
    $runner = new class extends SymfonyWhisperxProcessRunner {
        private bool $simulateReusedPid = true;

        protected function linuxProcessIdentity(int $pid): ?array
        {
            $identity = parent::linuxProcessIdentity($pid);
            if ($identity !== null && $this->simulateReusedPid) {
                $this->simulateReusedPid = false;
                $identity['start_ticks'] = 'previous-process-start-time';
                $identity['process_group_id'] = max(2, $pid - 1);
                $identity['session_id'] = max(2, $pid - 2);
            }
            return $identity;
        }
    };

    try {
        $runner->run([PHP_BINARY, '-r', 'usleep(5_000_000);'], 5);
        test()->fail('Expected a changed process start identity to fail closed.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessIdentityChanged');
    }
});

it('fails after the Linux isolation deadline without signaling a shared unrelated process group', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux isolation-deadline regression test did not run: Linux setsid and POSIX signals are required.');
    }
    $runner = new class extends SymfonyWhisperxProcessRunner {
        protected function linuxProcessIdentity(int $pid): ?array
        {
            $identity = parent::linuxProcessIdentity($pid);
            if ($identity !== null) {
                // Simulate a readable /proc snapshot that still reports the
                // inherited group/session rather than the setsid transition.
                $identity['process_group_id'] = posix_getpgid(0);
                $identity['session_id'] = posix_getsid(0);
            }
            return $identity;
        }
    };
    $marker = tempnam(sys_get_temp_dir(), 'whisperx-unrelated-group-');
    expect(unlink($marker))->toBeTrue();
    $unrelated = proc_open([PHP_BINARY, '-r', '$marker = '.var_export($marker, true).'; usleep(1800000); file_put_contents($marker, "alive");'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, null, ['bypass_shell' => true]);
    expect(is_resource($unrelated))->toBeTrue();
    $unrelatedPid = (int) (proc_get_status($unrelated)['pid'] ?? 0);
    expect(posix_getpgid($unrelatedPid))->toBe(posix_getpgid(0));
    $startedAt = microtime(true);

    try {
        try {
            $runner->run([PHP_BINARY, '-r', 'usleep(5_000_000);'], 5);
            test()->fail('Expected process-group verification to time out.');
        } catch (WhisperxAlignmentException $exception) {
            expect($exception->safeCode)->toBe('ProcessGroupIsolationTimedOut');
        }
        expect(microtime(true) - $startedAt)->toBeLessThan(2.0);
        usleep(1_000_000);
        expect(file_exists($marker))->toBeTrue('The unrelated process in the inherited group was terminated.');
    } finally {
        if (is_resource($unrelated)) {
            $status = proc_get_status($unrelated);
            if ($status['running']) @proc_terminate($unrelated, 9);
            @proc_close($unrelated);
        }
        if (file_exists($marker)) @unlink($marker);
    }
});

it('terminates descendant processes with the WhisperX process group on Linux', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Isolated process-group verification requires Linux setsid and POSIX signals.');
    }

    $marker = tempnam(sys_get_temp_dir(), 'whisperx-child-');
    $unrelatedMarker = tempnam(sys_get_temp_dir(), 'whisperx-unrelated-');
    expect(unlink($marker))->toBeTrue()->and(unlink($unrelatedMarker))->toBeTrue();
    $childCode = '$marker = '.var_export($marker, true).'; usleep(2200000); file_put_contents($marker, "orphan");';
    $childArgument = var_export($childCode, true);
    $parentCode = '$child = proc_open(['.var_export(PHP_BINARY, true).', "-r", '.$childArgument.'], '
        .'[0 => ["file", "/dev/null", "r"], 1 => ["file", "/dev/null", "a"], 2 => ["file", "/dev/null", "a"]], $pipes, null, null, ["bypass_shell" => true]); '
        .'usleep(10000000);';
    $unrelated = proc_open([PHP_BINARY, '-r', '$marker = '.var_export($unrelatedMarker, true).'; usleep(2200000); file_put_contents($marker, "alive");'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $unrelatedPipes, null, null, ['bypass_shell' => true]);
    $groupId = null;

    try {
        (new SymfonyWhisperxProcessRunner())->run([PHP_BINARY, '-r', $parentCode], 1, function (?int $pid, ?array $identity) use (&$groupId): void {
            $groupId = $identity['process_group_id'] ?? null;
        });
        test()->fail('Expected the parent process to time out.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessTimedOut');
    }
    usleep(1_500_000);
    expect(file_exists($marker))->toBeFalse('A descendant survived process-group cleanup.')
        ->and(file_exists($unrelatedMarker))->toBeTrue('An unrelated process was terminated with the alignment group.');
    expect($groupId)->toBeGreaterThan(1);
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $stat = @file_get_contents($path);
        if (is_string($stat) && preg_match('/^\d+ \(.*\) (.*)$/', $stat, $parts)) {
            $fields = preg_split('/\s+/', trim($parts[1]));
            expect(!is_array($fields) || ($fields[0] ?? '') === 'Z' || (int) ($fields[2] ?? 0) !== $groupId)
                ->toBeTrue('A live process remains in the alignment process group.');
        }
    }
    if (is_resource($unrelated)) proc_close($unrelated);
});

it('terminates Windows descendants with the parent process tree on timeout', function () {
    if (DIRECTORY_SEPARATOR !== '\\') $this->markTestSkipped('Windows process-tree verification runs on Windows.');
    $marker = tempnam(sys_get_temp_dir(), 'whisperx-win-child-');
    $parentMarker = tempnam(sys_get_temp_dir(), 'whisperx-win-parent-');
    expect(unlink($marker))->toBeTrue()->and(unlink($parentMarker))->toBeTrue();
    $childCode = '$marker = '.var_export($marker, true).'; usleep(2200000); file_put_contents($marker, "orphan");';
    $parentCode = '$child = proc_open(['.var_export(PHP_BINARY, true).', "-r", '.var_export($childCode, true).'], '
        .'[0 => ["file", "NUL", "r"], 1 => ["file", "NUL", "a"], 2 => ["file", "NUL", "a"]], $pipes, null, null, ["bypass_shell" => true]); '
        .'usleep(2200000); file_put_contents('.var_export($parentMarker, true).', "orphan"); usleep(10000000);';
    try {
        (new SymfonyWhisperxProcessRunner())->run([PHP_BINARY, '-r', $parentCode], 1);
        test()->fail('Expected the parent process to time out.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessTimedOut');
    }
    usleep(1_500_000);
    expect(file_exists($marker))->toBeFalse('A Windows descendant survived process-tree cleanup.')
        ->and(file_exists($parentMarker))->toBeFalse('The Windows parent process survived timeout cleanup.');
});

it('cleans up descendants when heartbeat handling throws', function () {
    $spawnedMarker = tempnam(sys_get_temp_dir(), 'whisperx-spawned-');
    $lateMarker = tempnam(sys_get_temp_dir(), 'whisperx-exception-child-');
    expect(unlink($spawnedMarker))->toBeTrue()->and(unlink($lateMarker))->toBeTrue();
    $childCode = '$spawned = '.var_export($spawnedMarker, true).'; $late = '.var_export($lateMarker, true).'; '
        .'file_put_contents($spawned, "ready"); usleep(2200000); file_put_contents($late, "orphan");';
    $parentCode = '$child = proc_open(['.var_export(PHP_BINARY, true).', "-r", '.var_export($childCode, true).'], '
        .'[0 => ["file", '.var_export(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', true).', "r"], '
        .'1 => ["file", '.var_export(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', true).', "a"], '
        .'2 => ["file", '.var_export(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', true).', "a"]], $pipes, null, null, ["bypass_shell" => true]); '
        .'while (!file_exists('.var_export($spawnedMarker, true).')) usleep(10000); usleep(10000000);';
    try {
        (new SymfonyWhisperxProcessRunner())->run([PHP_BINARY, '-r', $parentCode], 10, function () use ($spawnedMarker): void {
            $limit = microtime(true) + 3;
            while (!file_exists($spawnedMarker) && microtime(true) < $limit) usleep(10000);
            throw new RuntimeException('Simulated heartbeat failure.');
        });
        test()->fail('Expected heartbeat handling to fail the process run.');
    } catch (WhisperxAlignmentException $exception) {
        expect($exception->safeCode)->toBe('ProcessFailed');
    }
    usleep(1_500_000);
    expect(file_exists($lateMarker))->toBeFalse('A descendant survived exception cleanup.');
});

it('does not leave a descendant behind when the parent exits normally', function () {
    $spawnedMarker = tempnam(sys_get_temp_dir(), 'whisperx-normal-spawned-');
    $lateMarker = tempnam(sys_get_temp_dir(), 'whisperx-normal-child-');
    expect(unlink($spawnedMarker))->toBeTrue()->and(unlink($lateMarker))->toBeTrue();
    $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $childCode = '$spawned = '.var_export($spawnedMarker, true).'; $late = '.var_export($lateMarker, true).'; '
        .'file_put_contents($spawned, "ready"); usleep(1200000); file_put_contents($late, "orphan");';
    $parentCode = '$child = proc_open(['.var_export(PHP_BINARY, true).', "-r", '.var_export($childCode, true).'], '
        .'[0 => ["file", '.var_export($nullDevice, true).', "r"], 1 => ["file", '.var_export($nullDevice, true).', "a"], '
        .'2 => ["file", '.var_export($nullDevice, true).', "a"]], $pipes, null, null, ["bypass_shell" => true]); '
        .'while (!file_exists('.var_export($spawnedMarker, true).')) usleep(10000);';
    expect((new SymfonyWhisperxProcessRunner())->run([PHP_BINARY, '-r', $parentCode], 5))->toBe(0);
    usleep(1_500_000);
    expect(file_exists($lateMarker))->toBeFalse('A descendant survived normal parent exit.');
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

it('marks a queued alignment failed when its matching queue job is missing', function () {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson);
    $audio = whisperxPreparedAudio();
    Queue::fake();
    $usage = queueWhisperxForTest($lesson, $transcript, $audio, whisperxAdmin());
    $usage->forceFill(['updated_at' => now()->subSeconds(90)])->save();

    $state = app(WhisperxAlignmentReconciler::class)->reconcile($usage->fresh());
    expect($state['queue_state'])->toBe('missing')
        ->and($state['reconciled'])->toBeTrue()
        ->and($usage->fresh()->status)->toBe('failed')
        ->and($usage->fresh()->error_code)->toBe('QueueJobMissing');
});

it('keeps a pending job queued when no worker heartbeat exists and prevents duplicate dispatch', function () {
    $lesson = whisperxTestLesson();
    $transcript = whisperxTranscript($lesson);
    $audio = whisperxPreparedAudio();
    $admin = whisperxAdmin();
    Queue::fake();
    $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
    $token = $usage->result['queue_token'];
    DB::table('jobs')->insert([
        'queue' => 'whisperx-alignment', 'payload' => json_encode(['queue_token' => $token]),
        'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
    ]);

    $state = app(WhisperxAlignmentReconciler::class)->reconcile($usage->fresh());
    expect($state['queue_state'])->toBe('pending_no_worker')
        ->and($usage->fresh()->status)->toBe('queued');

    $this->postJson('/api/admin/lessons/'.$lesson->id.'/whisperx-alignments', [
        'transcript_operation_id' => $transcript->id, 'audio_path' => $audio,
    ])->assertOk()->assertJsonPath('already_submitted', true);
    Queue::assertPushed(RunWhisperxAlignment::class, 1);
});

it('recognizes delayed, reserved, and failed queue records without inventing terminal states', function () {
    $lesson = whisperxTestLesson();
    $admin = whisperxAdmin();
    Queue::fake();
    $reconciler = app(WhisperxAlignmentReconciler::class);
    $states = [];

    foreach ([
        'delayed' => ['reserved_at' => null, 'available_at' => now()->addMinutes(2)->timestamp],
        'reserved' => ['reserved_at' => now()->subSeconds(150)->timestamp, 'available_at' => now()->timestamp],
    ] as $expectedState => $queueFields) {
        $transcript = whisperxTranscript($lesson, ucfirst($expectedState).'.');
        $audio = whisperxPreparedAudio();
        $usage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
        if ($expectedState === 'reserved') $usage->forceFill(['updated_at' => now()->subSeconds(150)])->save();
        DB::table('jobs')->insert(['queue' => 'whisperx-alignment', 'payload' => json_encode(['queue_token' => $usage->result['queue_token']]),
            'attempts' => 1, ...$queueFields, 'created_at' => now()->timestamp]);
        $result = $reconciler->reconcile($usage->fresh());
        $states[] = [$result['queue_state'], $usage->fresh()->status];
    }

    $transcript = whisperxTranscript($lesson, 'Failed queue.');
    $audio = whisperxPreparedAudio();
    $failedUsage = queueWhisperxForTest($lesson, $transcript, $audio, $admin);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(), 'connection' => 'whisperx', 'queue' => 'whisperx-alignment',
        'payload' => json_encode(['queue_token' => $failedUsage->result['queue_token']]), 'exception' => 'controlled test failure', 'failed_at' => now(),
    ]);
    $failedState = $reconciler->reconcile($failedUsage->fresh());

    expect($states)->toBe([['delayed', 'queued'], ['reserved_stalled', 'queued']])
        ->and($failedState['queue_state'])->toBe('failed')
        ->and($failedUsage->fresh()->status)->toBe('failed')
        ->and($failedUsage->fresh()->error_code)->toBe('QueueJobFailed');
});

it('matches a legacy queued job by its serialized operation ID when no queue token exists', function () {
    Queue::fake();
    $lesson = whisperxTestLesson();
    $usage = AiOperationUsage::query()->create([
        'service' => WhisperxAlignmentService::ALIGNMENT_SERVICE, 'model' => 'whisperx-3.8.6',
        'lesson_id' => $lesson->id, 'status' => 'queued', 'idempotency_key' => 'whisperx-legacy-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'legacy'), 'result' => ['processing_started_at' => null],
    ]);
    $command = 'O:'.strlen(RunWhisperxAlignment::class).':"'.RunWhisperxAlignment::class.'":1:{s:7:"usageId";i:'.$usage->id.';}';
    DB::table('jobs')->insert([
        'queue' => 'whisperx-alignment', 'payload' => json_encode(['data' => [
            'commandName' => RunWhisperxAlignment::class, 'command' => $command,
        ]]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
    ]);

    $state = app(WhisperxAlignmentReconciler::class)->reconcile($usage);
    expect($state['queue_state'])->toBe('pending_no_worker')
        ->and($state['reconciled'])->toBeFalse()
        ->and($usage->fresh()->status)->toBe('queued');
});

it('reconciles an interrupted alignment only after its heartbeat expires and worker is confirmed gone', function () {
    if (!function_exists('posix_kill')) $this->markTestSkipped('Process liveness checks require POSIX support.');

    $lesson = whisperxTestLesson();
    $usage = AiOperationUsage::query()->create([
        'service' => WhisperxAlignmentService::ALIGNMENT_SERVICE, 'model' => 'whisperx-3.8.6',
        'lesson_id' => $lesson->id, 'status' => 'processing', 'idempotency_key' => 'whisperx-interrupted-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'interrupted'),
        'result' => [
            'queue_token' => (string) str()->uuid(), 'heartbeat_at' => now()->subSeconds(180)->toIso8601String(),
            'worker_pid' => PHP_INT_MAX, 'worker_host' => gethostname(), 'process_pid' => null,
        ],
    ]);

    $state = app(WhisperxAlignmentReconciler::class)->reconcile($usage);
    expect($state['reconciled'])->toBeTrue()
        ->and($usage->fresh()->status)->toBe('failed')
        ->and($usage->fresh()->error_code)->toBe('WorkerInterrupted');
});

it('keeps a stale-heartbeat alignment active when its exact subprocess identity is still alive', function () {
    if (DIRECTORY_SEPARATOR !== '\\' && (!function_exists('posix_kill') || !(new \Symfony\Component\Process\ExecutableFinder())->find('setsid'))) {
        $this->markTestSkipped('Process identity verification requires Windows Job Object support or Linux setsid/POSIX support.');
    }
    Storage::fake('local');
    $lesson = whisperxTestLesson();
    $admin = whisperxAdmin();
    $usage = AiOperationUsage::query()->create([
        'service' => WhisperxAlignmentService::ALIGNMENT_SERVICE, 'model' => 'whisperx-3.8.6',
        'lesson_id' => $lesson->id, 'status' => 'processing', 'idempotency_key' => 'whisperx-live-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', 'live alignment'),
        'result' => ['heartbeat_at' => now()->subSeconds(385)->toIso8601String(), 'worker_pid' => getmypid(), 'worker_host' => gethostname()],
    ]);
    $workspace = storage_path('framework/testing/whisperx-live-'.str()->uuid());
    $outputDirectory = Storage::disk('local')->path('whisperx-alignments/'.$lesson->id.'/'.$usage->id);
    if (!is_dir($outputDirectory)) mkdir($outputDirectory, 0700, true);
    if (!is_dir($workspace)) mkdir($workspace, 0700, true);
    $runnerPath = $workspace.DIRECTORY_SEPARATOR.'fake-aligner.php';
    file_put_contents($runnerPath, '<?php usleep(1500000);');
    config(['whisperx.script_path' => $runnerPath]);
    $evidence = null;

    try {
        (new SymfonyWhisperxProcessRunner())->run([PHP_BINARY, $runnerPath, '--output', $outputDirectory], 5,
            function (?int $processId, ?array $identity) use ($usage, $admin, &$evidence): void {
                $result = $usage->fresh()->result;
                $result['process_pid'] = $processId;
                $result['process_identity'] = $identity;
                $result['heartbeat_at'] = now()->subSeconds(385)->toIso8601String();
                $result['worker_pid'] = getmypid();
                $result['worker_host'] = gethostname();
                $usage->update(['result' => $result]);
                $evidence = app(WhisperxAlignmentReconciler::class)->reconcile($usage->fresh());
                $workflow = test()->actingAs($admin)->getJson('/api/admin/lessons/'.$usage->lesson_id.'/subtitle-workflow');
                $workflow->assertOk();
                $evidence['ui_worker_stalled'] = $workflow->json('alignments.0.queue.worker_stalled');
                $evidence['ui_queue_state'] = $workflow->json('alignments.0.queue_state');
            });
    } finally {
        @unlink($runnerPath);
        @rmdir($workspace);
    }

    expect($evidence['queue_state'] ?? null)->toBe('alignment_process_active')
        ->and($evidence['reconciled'] ?? null)->toBeFalse()
        ->and($evidence['ui_worker_stalled'] ?? null)->toBeFalse()
        ->and($evidence['ui_queue_state'] ?? null)->toBe('alignment_process_active')
        ->and($usage->fresh()->status)->toBe('processing');
});

it('does not terminate a Linux process when its saved start identity no longer matches the PID', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_kill')) {
        $this->markTestSkipped('Linux PID-reuse protection requires /proc and POSIX signals.');
    }
    $lesson = whisperxTestLesson();
    $process = proc_open([PHP_BINARY, '-r', 'sleep(10);'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, null, ['bypass_shell' => true]);
    expect(is_resource($process))->toBeTrue();
    $pid = (int) (proc_get_status($process)['pid'] ?? 0);
    $usage = AiOperationUsage::query()->create([
        'service' => 'whisperx_alignment', 'model' => 'whisperx', 'lesson_id' => $lesson->id,
        'status' => 'processing', 'idempotency_key' => 'whisperx-pid-reuse-'.str()->uuid(),
        'request_fingerprint' => hash('sha256', str()->uuid()),
        'result' => [
            'queue_token' => (string) str()->uuid(), 'heartbeat_at' => now()->subSeconds(180)->toIso8601String(),
            'worker_pid' => PHP_INT_MAX, 'worker_host' => gethostname(), 'process_pid' => $pid,
            'process_identity' => ['process_id' => $pid, 'process_group_id' => $pid, 'session_id' => $pid, 'start_ticks' => 'reused-pid'],
            'processing_started_at' => now()->subMinutes(4)->toIso8601String(),
        ],
    ]);
    try {
        $result = app(WhisperxAlignmentReconciler::class)->reconcile($usage);
        expect($result['reconciled'])->toBeTrue()
            ->and($usage->fresh()->status)->toBe('failed')
            ->and(@posix_kill($pid, 0))->toBeTrue('The unrelated process must remain alive when PID identity differs.');
    } finally {
        if (is_resource($process)) {
            @proc_terminate($process, 9);
            @proc_close($process);
        }
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
        $processes->shouldReceive('run')->twice()->andReturn(9);
    } else {
        $processes->shouldReceive('run')->twice()->andThrow(new WhisperxAlignmentException('ProcessTimedOut', 'WhisperX exceeded its configured processing timeout.'));
    }
    $this->app->instance(WhisperxProcessRunner::class, $processes);
    $job = Queue::pushed(RunWhisperxAlignment::class)->first();
    $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('attempts')->twice()->andReturn(1, 2);
    $job->setJob($queueJob);

    $finalException = null;
    foreach ([1 => 'queued', 2 => 'failed'] as $attempt => $expectedStatus) {
        try {
            $job->handle(app(WhisperxAlignmentExecutor::class));
            test()->fail('Expected the mocked process failure.');
        } catch (WhisperxAlignmentException $exception) {
            $finalException = $exception;
            expect($exception->safeCode)->toBe($safeCode)
                ->and($usage->fresh()->status)->toBe($expectedStatus);
        }
    }
    $job->failed($finalException);

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
