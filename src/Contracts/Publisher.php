<?php

declare(strict_types=1);

namespace LaravelOutbox\Contracts;

use LaravelOutbox\Envelope;

interface Publisher
{
    /** Return after successful handoff; throw on failure. Forward event->id for deduplication. */
    public function publish(Envelope $event): void;
}
