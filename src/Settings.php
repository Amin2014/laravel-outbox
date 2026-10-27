<?php

declare(strict_types=1);

namespace LaravelOutbox;

use InvalidArgumentException;

final readonly class Settings
{
    public function __construct(
        public ?string $connection = null,
        public int $leaseSeconds = 60,
        public int $maxAttempts = 10,
        public int $backoffSeconds = 5,
        public int $maxBackoffSeconds = 3600,
    ) {
        if ($leaseSeconds < 1 || $maxAttempts < 1 || $backoffSeconds < 1 || $maxBackoffSeconds < $backoffSeconds) {
            throw new InvalidArgumentException('Outbox lease, attempts and backoff must be positive; maximum backoff must be >= base.');
        }
    }

    public function delay(int $attempt): int
    {
        return (int) min($this->maxBackoffSeconds, $this->backoffSeconds * (2 ** min(30, max(0, $attempt - 1))));
    }
}
