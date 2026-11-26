<?php

$processTimeout = max(60, (int) env('WHISPERX_PROCESS_TIMEOUT', env('WHISPERX_TIMEOUT', 7200)));
$openaiWhisperJobTimeout = max(60, (int) env('OPENAI_WHISPER_HTTP_TIMEOUT', 1800) + 300);
$jobTimeout = max($processTimeout + 300, (int) env('WHISPERX_JOB_TIMEOUT', 7500), $openaiWhisperJobTimeout);
$workerTimeout = max($jobTimeout, (int) env('WHISPERX_WORKER_TIMEOUT', 7500));

return [
    'enabled' => (bool) env('WHISPERX_ENABLED', false),
    'python_binary' => env('WHISPERX_PYTHON_BINARY', 'python3'),
    'script_path' => env('WHISPERX_SCRIPT_PATH', base_path('tools/whisperx-aligner/align_transcript.py')),
    'device' => env('WHISPERX_DEVICE', 'cpu'),
    // Let Symfony stop the Python process first; Laravel then has time to catch
    // the timeout, clean partial output, and release the job for its retry.
    'process_timeout' => $processTimeout,
    'job_timeout' => $jobTimeout,
    'worker_timeout' => $workerTimeout,
    'retry_after' => max($workerTimeout + 300, (int) env('WHISPERX_RETRY_AFTER', 7800)),
    'connection' => 'whisperx',
    'queue' => 'whisperx-alignment',
];
