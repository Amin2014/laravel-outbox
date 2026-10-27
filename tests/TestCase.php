<?php

declare(strict_types=1);

namespace LaravelOutbox\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use LaravelOutbox\Outbox;
use LaravelOutbox\OutboxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private ?string $sqliteFile = null;

    protected function getPackageProviders($app): array
    {
        return [OutboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('TEST_DB') ?: 'sqlite';
        $this->sqliteFile = $driver === 'sqlite' ? tempnam(sys_get_temp_dir(), 'outbox-test-') : null;
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => $driver,
            'database' => $this->sqliteFile ?? 'outbox_test',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'username' => getenv('DB_USERNAME') ?: 'outbox',
            'password' => getenv('DB_PASSWORD') ?: 'outbox',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ]);
        $app['config']->set('outbox.connection', 'testing');
        $app['config']->set('outbox.lease_seconds', 10);
        $app['config']->set('outbox.backoff_seconds', 2);
        $app['config']->set('outbox.max_backoff_seconds', 8);
        $app['config']->set('outbox.max_attempts', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-27 10:00:00 UTC');
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->dropIfExists('outbox_events');
        $schema->dropIfExists('orders');
        (require __DIR__.'/../database/migrations/2026_01_01_000000_create_outbox_events_table.php')->up();
        $schema->create('orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('reference');
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
        if ($this->sqliteFile !== null) {
            @unlink($this->sqliteFile);
        }
    }

    protected function record(): string
    {
        return $this->app['db']->transaction(fn (): string =>
            $this->app->make(Outbox::class)->record('order.placed.v1', ['order_id' => 42], ['trace_id' => 'abc'])
        );
    }

    protected function row(string $id): object
    {
        return $this->app['db']->table('outbox_events')->where('id', $id)->first();
    }

    protected function advance(int $seconds): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds($seconds));
    }
}
