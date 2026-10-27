<?php

declare(strict_types=1);

namespace LaravelOutbox;

final readonly class Envelope
{
    /** @param array<string|int, mixed> $payload @param array<string|int, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public array $metadata,
        public int $attempt,
        public string $occurredAt,
    ) {}
}
