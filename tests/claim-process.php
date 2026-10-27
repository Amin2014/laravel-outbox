<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager;
use LaravelOutbox\OutboxStore;
use LaravelOutbox\Settings;

[$script, $configPath, $barrier, $name] = $argv;
$config = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$capsule = new Manager();
$capsule->addConnection($config);
CarbonImmutable::setTestNow('2026-10-27 10:00:00 UTC');
// Establish an independent PDO session before signalling readiness.
$capsule->getConnection()->getPdo();
file_put_contents($barrier.'.'.$name, 'ready');
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timed out'); }
    usleep(1000);
}
$store = new OutboxStore($capsule->getDatabaseManager(), new Settings());
$claim = $store->claim();
echo json_encode($claim?->event->id, JSON_THROW_ON_ERROR);
