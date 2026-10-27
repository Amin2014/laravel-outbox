<?php

declare(strict_types=1);

namespace LaravelOutbox\Tests;

use LaravelOutbox\Contracts\Publisher;
use LaravelOutbox\Envelope;
use LaravelOutbox\Outbox;
use LaravelOutbox\OutboxStore;
use LaravelOutbox\Settings;
use LaravelOutbox\Worker;
use LogicException;
use Psr\Log\NullLogger;
use RuntimeException;

final class OutboxTest extends TestCase
{
    public function test_successful_delivery_via_laravel_event_dispatcher_and_artisan(): void
    {
        $events = [];
        $this->app['events']->listen(Envelope::class, function (Envelope $event) use (&$events): void {
            $events[] = $event;
        });
        $id = $this->record();
        $this->artisan('outbox:publish', ['--limit' => 1])->assertSuccessful()->run();
        self::assertCount(1, $events);
        self::assertSame($id, $events[0]->id);
        self::assertSame(['order_id' => 42], $events[0]->payload);
        self::assertSame(['trace_id' => 'abc'], $events[0]->metadata);
        self::assertSame('delivered', $this->row($id)->status);
        self::assertNotNull($this->row($id)->published_at);
        self::assertNull($this->app->make(OutboxStore::class)->claim());
    }

    public function test_rollback_removes_business_write_and_outbox_event(): void
    {
        try {
            $this->app['db']->transaction(function (): void {
                $this->app['db']->table('orders')->insert(['reference' => 'A']);
                $this->app->make(Outbox::class)->record('order.placed', ['reference' => 'A']);
                throw new RuntimeException('abort');
            });
            self::fail('Transaction should throw.');
        } catch (RuntimeException $e) {
            self::assertSame('abort', $e->getMessage());
        }
        self::assertSame(0, $this->app['db']->table('orders')->count());
        self::assertSame(0, $this->app['db']->table('outbox_events')->count());
    }

    public function test_other_connection_cannot_observe_uncommitted_outbox(): void
    {
        $this->app['config']->set('database.connections.reader', $this->app['config']->get('database.connections.testing'));
        $this->app['db']->transaction(function (): void {
            $this->app->make(Outbox::class)->record('event', []);
            self::assertSame(0, $this->app['db']->connection('reader')->table('outbox_events')->count());
        });
        self::assertSame(1, $this->app['db']->connection('reader')->table('outbox_events')->count());
    }

    public function test_record_without_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->app->make(Outbox::class)->record('event', []);
    }

    public function test_transaction_on_different_connection_is_not_accepted(): void
    {
        $this->app['config']->set('database.connections.other', $this->app['config']->get('database.connections.testing'));
        $this->expectException(LogicException::class);
        $this->app['db']->connection('other')->transaction(fn () => $this->app->make(Outbox::class)->record('event', []));
    }

    public function test_claim_inside_business_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->app['db']->transaction(fn () => $this->app->make(OutboxStore::class)->claim());
    }

    public function test_live_claim_is_exclusive_and_expired_claim_can_be_recovered(): void
    {
        $id = $this->record();
        $store = $this->app->make(OutboxStore::class);
        $first = $store->claim();
        self::assertNotNull($first);
        self::assertNull($store->claim());
        $this->advance(10);
        self::assertFalse($store->acknowledge($first));
        $second = $store->claim();
        self::assertNotNull($second);
        self::assertSame($id, $second->event->id);
        self::assertSame(2, $second->event->attempt);
        self::assertNotSame($first->token, $second->token);
        self::assertFalse($store->acknowledge($first));
        self::assertFalse($store->fail($first, new RuntimeException('stale')));
        self::assertTrue($store->acknowledge($second));
    }

    public function test_success_then_crash_before_acknowledgement_produces_duplicate_id(): void
    {
        $id = $this->record();
        $publisher = new class implements Publisher {
            public array $ids = [];
            public function publish(Envelope $event): void { $this->ids[] = $event->id; }
        };
        $store = $this->app->make(OutboxStore::class);
        $claim = $store->claim();
        $publisher->publish($claim->event);
        // Simulated process death: no ack/fail. A replacement worker starts after expiry.
        $this->advance(11);
        $worker = new Worker($store, $publisher, new NullLogger());
        self::assertSame('delivered', $worker->processOne());
        self::assertSame([$id, $id], $publisher->ids);
        self::assertSame('delivered', $this->row($id)->status);
    }

    public function test_retries_back_off_then_fail_and_operator_can_requeue(): void
    {
        $id = $this->record();
        $publisher = new class implements Publisher {
            public function publish(Envelope $event): void { throw new RuntimeException('broker unavailable'); }
        };
        $store = $this->app->make(OutboxStore::class);
        $worker = new Worker($store, $publisher, new NullLogger());
        self::assertSame('retry', $worker->processOne());
        self::assertSame('pending', $this->row($id)->status);
        self::assertStringContainsString('broker unavailable', $this->row($id)->last_error);
        self::assertNull($worker->processOne());
        $this->advance(2);
        self::assertSame('retry', $worker->processOne());
        $this->advance(3);
        self::assertNull($worker->processOne());
        $this->advance(1);
        self::assertSame('retry', $worker->processOne());
        self::assertSame('failed', $this->row($id)->status);
        self::assertNull($worker->processOne());
        $this->artisan('outbox:retry', ['id' => $id])->assertSuccessful()->run();
        self::assertSame(0, (int) $this->row($id)->attempts);
        self::assertSame($id, $store->claim()->event->id);
    }

    public function test_crash_on_final_attempt_is_marked_failed_when_lease_expires(): void
    {
        $id = $this->record();
        $store = $this->app->make(OutboxStore::class);
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame($i, $store->claim()->event->attempt);
            $this->advance(10);
        }
        self::assertNull($store->claim());
        self::assertSame('failed', $this->row($id)->status);
        self::assertStringContainsString('outcome unknown', $this->row($id)->last_error);
    }

    public function test_slow_publish_reports_lease_loss_and_remains_recoverable(): void
    {
        $id = $this->record();
        $publisher = new class implements Publisher {
            public function publish(Envelope $event): void
            {
                \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addSeconds(11));
            }
        };
        $store = $this->app->make(OutboxStore::class);
        self::assertSame('lost', (new Worker($store, $publisher, new NullLogger()))->processOne());
        self::assertSame('processing', $this->row($id)->status);
        self::assertSame(2, $store->claim()->event->attempt);
    }

    public function test_invalid_json_payload_rolls_back_business_operation(): void
    {
        try {
            $this->app['db']->transaction(function (): void {
                $this->app['db']->table('orders')->insert(['reference' => 'B']);
                $this->app->make(Outbox::class)->record('invalid', ['value' => INF]);
            });
            self::fail('Encoding must fail.');
        } catch (\JsonException) {
            self::assertSame(0, $this->app['db']->table('orders')->count());
        }
    }

    public function test_backoff_is_capped_and_configuration_validated(): void
    {
        self::assertSame(8, (new Settings(backoffSeconds: 2, maxBackoffSeconds: 8))->delay(100));
        $this->expectException(\InvalidArgumentException::class);
        new Settings(leaseSeconds: 0);
    }

    public function test_command_validates_limit_and_reports_publish_failure(): void
    {
        $this->artisan('outbox:publish', ['--limit' => '0'])->assertExitCode(2)->run();
        $this->record();
        $this->app->instance(Publisher::class, new class implements Publisher {
            public function publish(Envelope $event): void { throw new RuntimeException('no'); }
        });
        $this->artisan('outbox:publish')->assertExitCode(1)->run();
    }
}
