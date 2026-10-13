<?php

namespace Lester\EloquentSalesForce\Tests;

use Lester\EloquentSalesForce\Facades\SObjects;
use Lester\EloquentSalesForce\Fakers\SObjectsFake;
use Lester\EloquentSalesForce\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected SObjectsFake $salesforce;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesforce = SObjects::fake();
    }

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:WRAf0EDpFqwpbS829xKy2MGEkcJxIEmMrwFIZbGxIqE=');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('eloquent_sf.logging', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Skip tests that need a local database when SQLite is unavailable.
     */
    protected function requireSqlite(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite extension is required for this test.');
        }
    }
}
