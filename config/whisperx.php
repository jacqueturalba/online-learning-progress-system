<?php

return [
    'enabled' => (bool) env('WHISPERX_ENABLED', false),
    'python_binary' => env('WHISPERX_PYTHON_BINARY', 'python3'),
    'script_path' => env('WHISPERX_SCRIPT_PATH', base_path('tools/whisperx-aligner/align_transcript.py')),
    'device' => env('WHISPERX_DEVICE', 'cpu'),
    'timeout' => max(60, (int) env('WHISPERX_TIMEOUT', 7200)),
    'connection' => 'whisperx',
    'queue' => 'whisperx-alignment',
];
