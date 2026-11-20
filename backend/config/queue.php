<?php

return [
    // On shared hosting use 'database' (no Redis). Jobs are then drained by the
    // per-minute cron (queue:work in the scheduler), so webhooks and other heavy
    // work run in the worker — NOT inside the web request — which keeps the number
    // of concurrent PHP processes low and avoids 503s from the host's process cap.
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        // Runs jobs immediately, inline in the current process. Fine for local/dev,
        // but in production it makes a webhook hold a PHP process for the whole job
        // (incl. slow AI calls), which is exactly what overloads shared hosting.
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 120),
            'block_for' => null,
            'after_commit' => false,
        ],
    ],

    // Control how batch metadata and failed jobs are stored.
    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
