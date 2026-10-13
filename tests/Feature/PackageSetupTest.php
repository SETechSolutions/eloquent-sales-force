<?php

namespace Lester\EloquentSalesForce\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Lester\EloquentSalesForce\Database\SOQLConnection;
use Lester\EloquentSalesForce\Facades\SObjects;
use Lester\EloquentSalesForce\ServiceProvider;
use Lester\EloquentSalesForce\Tests\Fixtures\TestLead;
use Lester\EloquentSalesForce\Tests\TestCase;

class PackageSetupTest extends TestCase
{
    public function test_forrest_reads_its_settings_from_the_package_config(): void
    {
        $this->assertSame(config('eloquent_sf.forrest'), config('forrest'));
        $this->assertSame('UserPassword', config('forrest.authentication'));
        $this->assertSame(3600, config('forrest.storage.expire_in'));
        $this->assertTrue(config('forrest.client.verify'));
    }

    public function test_credentials_from_a_legacy_soql_database_connection_take_precedence(): void
    {
        config([
            'eloquent_sf.forrest.credentials.consumerKey' => 'from-env',
            'eloquent_sf.forrest.credentials.loginURL' => 'https://login.salesforce.com',
            'database.connections.soql' => [
                'driver' => 'soql',
                'database' => null,
                'consumerKey' => 'legacy-key',
                'loginURL' => 'https://test.salesforce.com',
                'username' => null,
            ],
        ]);

        (new ServiceProvider($this->app))->register();

        $this->assertSame('legacy-key', config('forrest.credentials.consumerKey'));
        $this->assertSame('https://test.salesforce.com', config('forrest.credentials.loginURL'));
        $this->assertArrayNotHasKey('driver', config('forrest.credentials'));
    }

    public function test_models_do_not_need_a_database_connection(): void
    {
        config(['database.default' => 'missing']);

        $this->assertInstanceOf(SOQLConnection::class, (new TestLead)->getConnection());
        $this->assertSame('soql', (new TestLead)->getConnection()->getName());
        $this->assertCount(0, TestLead::select('Id')->get());
    }

    public function test_the_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('make:salesforce', $commands);
        $this->assertContains('salesforce:sync', $commands);
        $this->assertContains('db:sync', $commands);
    }

    public function test_no_routes_are_registered_by_default(): void
    {
        $this->assertFalse(Route::has('eloquent-sf.login'));
        $this->assertFalse(Route::has('eloquent-sf.sync'));
    }

    public function test_the_fake_is_swapped_in_through_the_facade(): void
    {
        $fake = SObjects::fake(['Lead' => [['Id' => '00Q000000000001AAA']]]);

        $this->assertCount(1, TestLead::select('Id')->get());

        $fake->assertAuthenticated();
        $fake->assertQueried('select Id from Lead');
        $fake->assertNotQueried('select Id from Task');
        $fake->assertNotCreated('Lead');
        $fake->assertNothingWritten();
    }

    public function test_save_or_fail_works_without_database_transactions(): void
    {
        $lead = new TestLead(['LastName' => 'Lovelace']);

        $this->assertTrue($lead->saveOrFail());
        $this->salesforce->assertCreated('Lead', ['LastName' => 'Lovelace']);
    }
}
