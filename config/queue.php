<?php

return [
    'default' => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => max(630, (int) env('DB_QUEUE_RETRY_AFTER', 630)),
            'after_commit' => false,
        ],
        'whisperx' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'whisperx-alignment',
            'retry_after' => max(300, (int) env('WHISPERX_TIMEOUT', 7200) + 300),
            'after_commit' => true,
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => max(630, (int) env('REDIS_QUEUE_RETRY_AFTER', 630)),
            'block_for' => null,
            'after_commit' => false,
        ],
    ],
    'batching' => ['database' => env('DB_CONNECTION', 'mysql'), 'table' => 'job_batches'],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
