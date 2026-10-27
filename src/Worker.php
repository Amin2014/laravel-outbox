<?php

declare(strict_types=1);

namespace LaravelOutbox;

use LaravelOutbox\Contracts\Publisher;
use Psr\Log\LoggerInterface;
use Throwable;

final class Worker
{
    public function __construct(private OutboxStore $store, private Publisher $publisher, private LoggerInterface $logger) {}

    /** null = no claim; retry = publisher failure (possibly terminal); lost = lease loss. */
    public function processOne(): ?string
    {
        $claim = $this->store->claim();
        if ($claim === null) {
            return null;
        }
        try {
            $this->publisher->publish($claim->event);
        } catch (Throwable $error) {
            // A storage failure here propagates: the lease will be recovered later.
            $owned = $this->store->fail($claim, $error);
            $this->logger->warning('Outbox publish failed', [
                'event_id' => $claim->event->id, 'attempt' => $claim->event->attempt,
                'lease_owned' => $owned, 'exception_class' => $error::class,
            ]);
            return $owned ? 'retry' : 'lost';
        }
        // Never turn an acknowledgement storage error into a publisher failure.
        if (!$this->store->acknowledge($claim)) {
            $this->logger->warning('Outbox delivery completed after lease loss', ['event_id' => $claim->event->id]);
            return 'lost';
        }
        return 'delivered';
    }
}
