<?php

namespace Hdruk\LaravelHubspotManager\Providers;

use Illuminate\Support\ServiceProvider;
use Hdruk\LaravelHubspotManager\Commands\SyncHubspotContacts;
use Hdruk\LaravelHubspotManager\Services\Hubspot;

/**
 * This file is part of the Laravel Hubspot Manager package.
 *
 * @author Loki Sinclair <loki.sinclair@hdruk.ac.uk> (C)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class LaravelHubspotManagerProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/hubspotmanager.php' => config_path('hubspotmanager.php'),
        ], 'hubspot-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncHubspotContacts::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/hubspotmanager.php', 'hubspotmanager');

        $this->app->singleton(Hubspot::class, fn () => new Hubspot());
    }
}