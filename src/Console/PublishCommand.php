<?php

declare(strict_types=1);

namespace LaravelOutbox\Console;

use Illuminate\Console\Command;
use LaravelOutbox\Worker;

final class PublishCommand extends Command
{
    protected $signature = 'outbox:publish {--limit=100 : Maximum attempted deliveries in this run}';
    protected $description = 'Publish available outbox events and recover expired claims';

    public function handle(Worker $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            $this->error('--limit must be a positive integer.');
            return self::INVALID;
        }
        $counts = ['delivered' => 0, 'retry' => 0, 'lost' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $result = $worker->processOne();
            if ($result === null) {
                break;
            }
            $counts[$result]++;
        }
        $this->info(sprintf('Delivered: %d; publish failures: %d; lease losses: %d', ...array_values($counts)));
        return $counts['retry'] + $counts['lost'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
