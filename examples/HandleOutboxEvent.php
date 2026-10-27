<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use LaravelOutbox\Envelope;

/** Copy into app/Listeners and register it as described in README. */
final class HandleOutboxEvent implements ShouldQueue
{
    public int $tries = 5;

    public function handle(Envelope $event): void
    {
        if ($event->type !== 'order.placed.v1') {
            return;
        }
        // Logging demonstrates the handoff. Real state changes require a transactional
        // inbox with a unique event ID, or destination-supported idempotency keys.
        Log::info('Received order event', ['event_id' => $event->id, 'order_id' => $event->payload['order_id']]);
    }
}
