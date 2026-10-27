<?php

declare(strict_types=1);

namespace LaravelOutbox\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use LaravelOutbox\Envelope;

final class IdempotentListener implements ShouldQueue
{
    public function handle(Envelope $event): void
    {
        DB::transaction(function () use ($event): void {
            if (DB::table('consumer_inbox')->insertOrIgnore(['event_id' => $event->id]) === 0) {
                return;
            }
            DB::table('orders')->insert(['reference' => $event->id]);
        });
    }
}
