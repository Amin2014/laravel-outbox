<?php

declare(strict_types=1);

namespace LaravelOutbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class OutboxStore
{
    public function __construct(private DatabaseManager $database, private Settings $settings) {}

    private function connection(): Connection
    {
        $connection = $this->database->connection($this->settings->connection);
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Publish/claim must run outside any database transaction.');
        }
        return $connection;
    }

    private function table(): Builder
    {
        return $this->connection()->table('outbox_events')->useWritePdo();
    }

    private function eligible(int $now): Builder
    {
        return $this->table()->where('attempts', '<', $this->settings->maxAttempts)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $pending) use ($now): void {
                    $pending->where('status', 'pending')->where('available_at', '<=', $now);
                })->orWhere(function (Builder $expired) use ($now): void {
                    $expired->where('status', 'processing')->where('lease_expires_at', '<=', $now);
                });
            });
    }

    public function claim(): ?Claim
    {
        $now = CarbonImmutable::now('UTC');
        // A crash on the final attempt must not leave a processing row forever.
        $this->table()->where('status', 'processing')->where('lease_expires_at', '<=', $now->getTimestamp())
            ->where('attempts', '>=', $this->settings->maxAttempts)->update([
                'status' => 'failed', 'claim_token' => null, 'lease_expires_at' => null,
                'last_error' => 'Lease expired after final attempt; delivery outcome unknown.', 'updated_at' => $now,
            ]);

        // Snapshot candidates are hints. Only the conditional UPDATE grants ownership.
        $ids = $this->eligible($now->getTimestamp())->orderBy('available_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $token = (string) Str::uuid();
            $won = $this->eligible($now->getTimestamp())->where('id', $id)->update([
                'status' => 'processing', 'claim_token' => $token,
                'lease_expires_at' => $now->getTimestamp() + $this->settings->leaseSeconds,
                'attempts' => $this->connection()->raw('attempts + 1'), 'updated_at' => $now,
            ]);
            if ($won !== 1) {
                continue;
            }
            $row = $this->table()->where('id', $id)->where('claim_token', $token)->first();
            if ($row === null) {
                continue;
            }
            return new Claim(new Envelope(
                $row->id, $row->type,
                json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
                json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR),
                (int) $row->attempts, $row->created_at,
            ), $token);
        }
        return null;
    }

    private function owned(Claim $claim, int $now): Builder
    {
        return $this->table()->where('id', $claim->event->id)->where('status', 'processing')
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now);
    }

    public function acknowledge(Claim $claim): bool
    {
        $now = CarbonImmutable::now('UTC');
        return $this->owned($claim, $now->getTimestamp())->update([
            'status' => 'delivered', 'published_at' => $now, 'updated_at' => $now,
            'claim_token' => null, 'lease_expires_at' => null, 'last_error' => null,
        ]) === 1;
    }

    public function fail(Claim $claim, Throwable $error): bool
    {
        $now = CarbonImmutable::now('UTC');
        return $this->owned($claim, $now->getTimestamp())->update([
            'status' => $claim->event->attempt >= $this->settings->maxAttempts ? 'failed' : 'pending',
            'available_at' => $now->getTimestamp() + $this->settings->delay($claim->event->attempt),
            'last_error' => mb_substr($error::class.': '.$error->getMessage(), 0, 4000),
            'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => $now,
        ]) === 1;
    }

    /** Explicit operator action. The same event ID is retained. */
    public function retry(string $id): bool
    {
        $now = CarbonImmutable::now('UTC');
        return $this->table()->where('id', $id)->where('status', 'failed')->update([
            'status' => 'pending', 'attempts' => 0, 'available_at' => $now->getTimestamp(),
            'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => $now,
        ]) === 1;
    }
}
