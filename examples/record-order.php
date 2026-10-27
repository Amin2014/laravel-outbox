<?php

declare(strict_types=1);

// Run inside a bootstrapped Laravel application (for example, an Artisan command).
// Assumes orders has an auto-increment id and a reference string column.
use Illuminate\Support\Facades\DB;
use LaravelOutbox\Outbox;

$connection = DB::connection(config('outbox.connection'));
$eventId = $connection->transaction(function () use ($connection): string {
    $id = $connection->table('orders')->insertGetId(['reference' => 'ORDER-123']);
    return app(Outbox::class)->record('order.placed.v1', ['order_id' => $id]);
});

echo $eventId.PHP_EOL;
