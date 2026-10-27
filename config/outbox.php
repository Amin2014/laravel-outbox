<?php

declare(strict_types=1);

use LaravelOutbox\Publishers\LaravelEventPublisher;

return [
    // This MUST be the connection used by your business transaction.
    'connection' => env('OUTBOX_DB_CONNECTION'),
    'publisher' => LaravelEventPublisher::class,
    'lease_seconds' => 60,
    'max_attempts' => 10,
    'backoff_seconds' => 5,
    'max_backoff_seconds' => 3600,
];
