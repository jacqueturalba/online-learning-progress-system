<?php

use App\Models\User;
use App\Models\Lesson;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Services\AiOperationService;
use App\Services\LessonAudioPreparationException;
use App\Services\LessonAudioPreparationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schedule;
use App\Services\StudentPaceGroupAssigner;
use App\Services\WhisperxAlignmentReconciler;
use App\Services\WhisperxAlignmentService;

Artisan::command('students:recalculate-pace-groups', function (StudentPaceGroupAssigner $assigner): int {
    $assigner->recalculateAll();
    $this->info('Student pace groups recalculated from lesson completion records.');
    return 0;
})->purpose('Recalculate automatic student pace groups for the active calendar period');

Schedule::command('students:recalculate-pace-groups')->dailyAt('00:00')->timezone(config('app.timezone'));

Artisan::command('whisperx:reconcile', function (WhisperxAlignmentReconciler $reconciler): int {
    AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)
        ->whereIn('status', ['queued', 'processing'])->orderBy('id')->chunkById(100, function ($operations) use ($reconciler): void {
            foreach ($operations as $operation) $reconciler->reconcile($operation);
        });
    $this->info('WhisperX queue and heartbeat evidence reconciled.');
    return 0;
})->purpose('Reconcile stale WhisperX operation states using queue and heartbeat evidence');

Schedule::command('whisperx:reconcile')->everyMinute()->withoutOverlapping(10)->timezone(config('app.timezone'));

Artisan::command('app:create-admin', function () {
    $name = trim((string) $this->ask('Admin name'));
    $email = strtolower(trim((string) $this->ask('Admin email')));

    if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A name and valid email address are required.');
        return 1;
    }

    if (User::where('email', $email)->exists()) {
        $this->error('That email already belongs to an account. No account was changed.');
        return 1;
    }

    $password = (string) $this->secret('Password (minimum 12 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    if (strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
        $this->error('Passwords must match and contain at least 12 characters.');
        return 1;
    }

    User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => 'admin', 'email_verified_at' => now()]);
    $this->info("Admin account created for {$email}.");
})->purpose('Create a production admin account without using development seed credentials');

Artisan::command('ai:prepare-lesson-audio {lesson : ID of one lesson to prepare locally} {--keep : Keep generated files for local manual inspection}', function (LessonAudioPreparationService $audio): int {
    if (!app()->environment(['local', 'testing'])) {
        $this->error('This diagnostic command is available only in local or testing environments.');
        return 1;
    }

    $lesson = Lesson::query()->find($this->argument('lesson'));
    if (!$lesson) {
        $this->error('Lesson not found.');
        return 1;
    }

    $prepared = null;
    try {
        $prepared = $audio->prepare($lesson);
        $this->info('Audio preparation succeeded. No AI provider was called.');
        $this->line('Temporary private workspace: '.storage_path('app/private/'.$prepared['workspace']));
        if ($this->option('keep')) $this->warn('Temporary files were retained by request; remove this private workspace when finished.');
        $this->line('Video duration: '.number_format($prepared['duration_seconds'], 2).' seconds');
        $this->line('Extracted audio: '.storage_path('app/private/'.$prepared['audio_path']));
        foreach ($prepared['chunks'] as $index => $chunk) {
            $this->line(sprintf('Chunk %d: %s | offset %s s | duration %s s | %s bytes | overlap before %s s',
                $index + 1,
                storage_path('app/private/'.$chunk['path']),
                number_format($chunk['offset_seconds'], 3),
                number_format($chunk['duration_seconds'], 3),
                number_format($chunk['size_bytes']),
                number_format($chunk['overlap_before_seconds'], 3)));
        }
        return 0;
    } catch (LessonAudioPreparationException $exception) {
        $this->error($exception->getMessage());
        return 1;
    } finally {
        if ($prepared && !$this->option('keep')) $audio->cleanup($prepared);
    }
})->purpose('Prepare one lesson video into temporary private audio files without calling an AI provider');

Artisan::command('ai:transcribe-chunk {lesson : Lesson ID} {audio_path : Absolute path to one prepared audio chunk} {offset : Original lesson timeline offset in seconds} {--language=en : Transcription language} {--idempotency-key= : Optional stable idempotency key} {--confirm : Explicitly authorize one paid transcription operation}', function (AiOperationService $operations): int {
    if (!app()->environment(['local', 'testing'])) {
        $this->error('This diagnostic command is available only in local or testing environments.');
        return 1;
    }
    if (!$this->option('confirm')) {
        $this->error('Transcription can incur API charges. Re-run with --confirm to submit this one chunk.');
        return 1;
    }
    if (config('queue.default') === 'sync') {
        $this->error('The sync queue driver is not allowed. Configure an asynchronous queue and start its worker first.');
        return 1;
    }

    $lesson = Lesson::query()->find($this->argument('lesson'));
    if (!$lesson) {
        $this->error('Lesson not found.');
        return 1;
    }

    $offset = filter_var($this->argument('offset'), FILTER_VALIDATE_FLOAT);
    if ($offset === false || !is_finite((float) $offset)) {
        $this->error('The timeline offset must be a finite number of seconds.');
        return 1;
    }
    $language = (string) $this->option('language');
    if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language)) {
        $this->error('Provide a valid language code, such as en.');
        return 1;
    }

    $tempDirectory = trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\');
    $preparedRoot = realpath(Storage::disk('local')->path($tempDirectory));
    $privateRoot = realpath(config('filesystems.disks.local.root'));
    $audioPath = realpath((string) $this->argument('audio_path'));
    if (!$preparedRoot || !$privateRoot || !$audioPath || !is_file($audioPath) || !is_readable($audioPath)) {
        $this->error('The prepared audio file does not exist or is not readable.');
        return 1;
    }
    $privatePrefix = rtrim($privateRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    $insidePrivateStorage = str_starts_with($preparedRoot, $privatePrefix);
    if (DIRECTORY_SEPARATOR === '\\') $insidePrivateStorage = str_starts_with(strtolower($preparedRoot), strtolower($privatePrefix));
    if (!$insidePrivateStorage) {
        $this->error('The configured AI audio preparation directory must be inside private application storage.');
        return 1;
    }
    $rootPrefix = rtrim($preparedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    $candidate = str_starts_with($audioPath, $rootPrefix);
    if (DIRECTORY_SEPARATOR === '\\') $candidate = str_starts_with(strtolower($audioPath), strtolower($rootPrefix));
    if (!$candidate) {
        $this->error('The audio file must be inside the private AI audio preparation directory.');
        return 1;
    }
    $maximumBytes = min(20 * 1024 * 1024, (int) config('ai_media.max_chunk_bytes', 20 * 1024 * 1024));
    if (filesize($audioPath) <= 0 || filesize($audioPath) > $maximumBytes) {
        $this->error('The audio chunk is empty or exceeds the configured safe chunk size.');
        return 1;
    }

    try {
        $assignment = $operations->assigned('transcription');
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
    $model = (string) $assignment['integration']->model;
    if ($assignment['provider']->key() !== 'openai' || $model !== 'gpt-transcribe'
        || !in_array($model, $assignment['provider']->supportedModels('transcription'), true)) {
        $this->error('Assign an enabled OpenAI account with the gpt-transcribe model before submitting this test.');
        return 1;
    }

    $key = (string) $this->option('idempotency-key');
    if ($key === '') {
        $key = 'chunk-'.substr(hash('sha256', implode('|', [$lesson->id, hash_file('sha256', $audioPath), sprintf('%.3f', $offset), $language])), 0, 56);
    }
    if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
        $this->error('The idempotency key must contain 8 to 100 letters, numbers, dots, underscores, colons, or hyphens.');
        return 1;
    }

    $existing = AiOperationUsage::query()->where('idempotency_key', $key)->first();
    if ($existing && in_array($existing->status, ['queued', 'processing', 'succeeded'], true)) {
        $this->error("This request was already submitted (operation {$existing->id}, status {$existing->status}). No duplicate was queued.");
        return 1;
    }

    try {
        $result = $operations->queueTranscription($audioPath, $key, $lesson->id, $language, (float) $offset);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }
    if ($result['already_submitted'] ?? false) {
        $this->error("This request was already submitted (operation {$result['usage_id']}, status {$result['status']}). No duplicate was queued.");
        return 1;
    }

    $this->info('One transcription operation was queued; no provider request ran in this command.');
    $this->line('Operation ID: '.$result['usage_id']);
    $this->line('Lesson ID: '.$lesson->id);
    $this->line('Model: '.$model);
    $this->line('Language: '.$language);
    $this->line('Timeline offset: '.number_format((float) $offset, 3).' seconds');
    $this->line('Queue status: '.$result['status']);
    return 0;
})->purpose('Queue one prepared private audio chunk for local OpenAI transcription testing');

Artisan::command('ai:transcribe-result {operation : AI operation usage ID}', function (): int {
    if (!app()->environment(['local', 'testing'])) {
        $this->error('This diagnostic command is available only in local or testing environments.');
        return 1;
    }
    $usage = AiOperationUsage::query()->where('service', 'transcription')->find($this->argument('operation'));
    if (!$usage) {
        $this->error('Transcription operation not found.');
        return 1;
    }
    $this->line('Operation ID: '.$usage->id.' | Status: '.$usage->status.' | Lesson ID: '.$usage->lesson_id.' | Model: '.$usage->model);
    if ($usage->status !== 'succeeded' || !is_array($usage->result)) {
        $this->line('Transcript is not available yet. '.($usage->error_message ?? 'Check the operation status again after the queue worker runs.'));
        return $usage->status === 'failed' ? 1 : 0;
    }
    $result = $usage->result;
    $this->line('Timeline offset: '.number_format((float) $usage->timeline_offset_seconds, 3).' seconds');
    $this->line('Timestamp status: '.($result['timestamp_status'] ?? 'unavailable'));
    $this->newLine();
    $this->line((string) ($result['text'] ?? ''));
    if (is_array($result['segments'] ?? null)) {
        $offset = (float) $usage->timeline_offset_seconds;
        foreach ($result['segments'] as $segment) {
            $this->line(sprintf('[%0.3f - %0.3f] %s', $offset + $segment['start'], $offset + $segment['end'], $segment['text']));
        }
    }
    return 0;
})->purpose('Read one local transcription result from the encrypted operation record');
