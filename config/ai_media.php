<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'temp_directory' => env('AI_AUDIO_TEMP_DIRECTORY', 'ai-audio-preparation'),
    'max_chunk_bytes' => (int) env('AI_AUDIO_MAX_CHUNK_BYTES', 20 * 1024 * 1024),
    'audio_bitrate' => env('AI_AUDIO_BITRATE', '64k'),
    'overlap_seconds' => (float) env('AI_AUDIO_CHUNK_OVERLAP_SECONDS', 1.5),
    'process_timeout_seconds' => (int) env('AI_AUDIO_PROCESS_TIMEOUT_SECONDS', 3600),
];
