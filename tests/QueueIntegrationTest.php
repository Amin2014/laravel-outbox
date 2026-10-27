<?php

declare(strict_types=1);

namespace LaravelOutbox\Tests;

use Illuminate\Database\Schema\Blueprint;
use LaravelOutbox\Envelope;
use LaravelOutbox\OutboxStore;
use LaravelOutbox\Publishers\LaravelEventPublisher;
use LaravelOutbox\Tests\Fixtures\IdempotentListener;
use LaravelOutbox\Worker;

final class QueueIntegrationTest extends TestCase
{
    public function test_database_queue_receives_duplicates_and_transactional_inbox_deduplicates(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->dropIfExists('jobs');
        $schema->dropIfExists('consumer_inbox');
        $schema->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('consumer_inbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
        });
        try {
            $this->app['config']->set('queue.default', 'database');
            $this->app['config']->set('queue.connections.database', [
                'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 60, 'after_commit' => false,
            ]);
            $this->app['events']->listen(Envelope::class, IdempotentListener::class);
            $id = $this->record();
            $store = $this->app->make(OutboxStore::class);
            $claim = $store->claim();
            $this->app->make(LaravelEventPublisher::class)->publish($claim->event);
            // Crash before outbox acknowledgement: the queue already has the event.
            self::assertSame(1, $this->app['db']->table('jobs')->count());
            $this->advance(11);
            self::assertSame('delivered', $this->app->make(Worker::class)->processOne());
            self::assertSame(2, $this->app['db']->table('jobs')->count());
            $queue = $this->app['queue']->connection('database');
            for ($i = 0; $i < 2; $i++) {
                $job = $queue->pop();
                self::assertNotNull($job);
                $job->fire();
                $job->delete();
            }
            self::assertSame(1, $this->app['db']->table('consumer_inbox')->where('event_id', $id)->count());
            self::assertSame(1, $this->app['db']->table('orders')->where('reference', $id)->count());
            self::assertSame('delivered', $this->row($id)->status);
        } finally {
            $schema->dropIfExists('jobs');
            $schema->dropIfExists('consumer_inbox');
        }
    }
}
