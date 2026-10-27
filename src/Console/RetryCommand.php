<?php

declare(strict_types=1);

namespace LaravelOutbox\Console;

use Illuminate\Console\Command;
use LaravelOutbox\OutboxStore;

final class RetryCommand extends Command
{
    protected $signature = 'outbox:retry {id : Failed event ID to retry with attempts reset}';
    protected $description = 'Requeue a failed outbox event (delivery may already have occurred)';

    public function handle(OutboxStore $store): int
    {
        if (!$store->retry((string) $this->argument('id'))) {
            $this->error('No failed event with that ID.');
            return self::FAILURE;
        }
        $this->info('Event requeued with the original ID.');
        return self::SUCCESS;
    }
}
