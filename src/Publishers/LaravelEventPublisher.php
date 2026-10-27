<?php

declare(strict_types=1);

namespace LaravelOutbox\Publishers;

use Illuminate\Contracts\Events\Dispatcher;
use LaravelOutbox\Contracts\Publisher;
use LaravelOutbox\Envelope;

/** Dispatches a typed Envelope through Laravel's event dispatcher. */
final class LaravelEventPublisher implements Publisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(Envelope $event): void
    {
        $this->events->dispatch($event);
    }
}
