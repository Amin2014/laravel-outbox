<?php

declare(strict_types=1);

namespace LaravelOutbox;

use Illuminate\Support\ServiceProvider;
use LaravelOutbox\Console\PublishCommand;
use LaravelOutbox\Console\RetryCommand;
use LaravelOutbox\Contracts\Publisher;

final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outbox.php', 'outbox');
        $this->app->singleton(Settings::class, function ($app): Settings {
            $config = $app['config']['outbox'];
            return new Settings($config['connection'], (int) $config['lease_seconds'],
                (int) $config['max_attempts'], (int) $config['backoff_seconds'], (int) $config['max_backoff_seconds']);
        });
        $this->app->bind(Publisher::class, fn ($app): Publisher => $app->make($app['config']['outbox.publisher']));
        $this->app->singleton(Outbox::class);
        $this->app->singleton(OutboxStore::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/outbox.php' => config_path('outbox.php')], 'outbox-config');
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'outbox-migrations');
            $this->commands([PublishCommand::class, RetryCommand::class]);
        }
    }
}
