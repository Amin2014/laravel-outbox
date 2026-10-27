<?php

declare(strict_types=1);

namespace LaravelOutbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class Outbox
{
    public function __construct(private DatabaseManager $database, private Settings $settings) {}

    /** @param array<string|int, mixed> $payload @param array<string|int, mixed> $metadata */
    public function record(string $type, array $payload, array $metadata = []): string
    {
        $connection = $this->database->connection($this->settings->connection);
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Outbox::record requires an active business transaction on the configured outbox connection.');
        }
        if (trim($type) === '' || strlen($type) > 191) {
            throw new InvalidArgumentException('Event type must contain 1 to 191 bytes.');
        }
        $id = (string) Str::uuid();
        $now = CarbonImmutable::now('UTC');
        $connection->table('outbox_events')->insert([
            'id' => $id, 'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'status' => 'pending', 'attempts' => 0, 'available_at' => $now->getTimestamp(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }
}
