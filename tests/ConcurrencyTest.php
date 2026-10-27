<?php

declare(strict_types=1);

namespace LaravelOutbox\Tests;

use Symfony\Component\Process\Process;

final class ConcurrencyTest extends TestCase
{
    public function test_stale_candidate_selection_cannot_overwrite_another_claim(): void
    {
        $id = $this->record();
        $this->app['config']->set('database.connections.competitor', $this->app['config']->get('database.connections.testing'));
        $other = new \LaravelOutbox\OutboxStore($this->app['db'], new \LaravelOutbox\Settings(connection: 'competitor'));
        $interleaved = false;
        $winner = null;
        $this->app['db']->listen(function ($query) use (&$interleaved, &$winner, $other): void {
            if (!$interleaved && $query->connectionName === 'testing'
                && str_starts_with(strtolower($query->sql), 'select')
                && str_contains(strtolower($query->sql), 'order by')) {
                // SELECT has returned its stale snapshot; race another session before UPDATE.
                $interleaved = true;
                $winner = $other->claim();
            }
        });
        self::assertNull($this->app->make(\LaravelOutbox\OutboxStore::class)->claim());
        self::assertTrue($interleaved);
        self::assertSame($id, $winner->event->id);
        self::assertSame($winner->token, $this->row($id)->claim_token);
        self::assertSame(1, (int) $this->row($id)->attempts);
    }

    public function test_two_independent_processes_compete_for_one_available_event(): void
    {
        $id = $this->record();
        $base = tempnam(sys_get_temp_dir(), 'outbox-barrier-');
        unlink($base);
        $configPath = $base.'.json';
        file_put_contents($configPath, json_encode($this->app['config']->get('database.connections.testing'), JSON_THROW_ON_ERROR));
        $processes = [];
        try {
            foreach (['a', 'b'] as $name) {
                $process = new Process([PHP_BINARY, __DIR__.'/claim-process.php', $configPath, $base, $name]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!file_exists($base.'.a') || !file_exists($base.'.b')) {
                if (microtime(true) > $deadline) {
                    self::fail('Workers did not reach barrier: '.implode('', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
                }
                usleep(1000);
            }
            touch($base);
            $results = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame([$id], array_values(array_filter($results)));
            self::assertSame(1, (int) $this->row($id)->attempts);
        } finally {
            foreach ($processes as $process) { $process->stop(); }
            foreach ([$base, $base.'.a', $base.'.b', $configPath] as $file) { @unlink($file); }
        }
    }
}
