<?php

namespace Hdruk\LaravelHubspotManager\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Hdruk\LaravelHubspotManager\Providers\LaravelHubspotManagerProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelHubspotManagerProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
