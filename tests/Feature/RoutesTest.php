<?php

namespace Lester\EloquentSalesForce\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lester\EloquentSalesForce\Http\Middleware\VerifySyncSecret;
use Lester\EloquentSalesForce\Tests\Fixtures\TestModel;
use Lester\EloquentSalesForce\Tests\TestCase;

class RoutesTest extends TestCase
{
    private const LEAD = '00Q000000000001AAA';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('eloquent_sf.forrest.authentication', 'WebServer');
        $app['config']->set('eloquent_sf.syncTwoWay', true);
        $app['config']->set('eloquent_sf.syncPriority', 'local');
        $app['config']->set('eloquent_sf.syncTwoWayModels', [TestModel::class]);
        $app['config']->set('eloquent_sf.routes.sync.secret', 's3cret');
        $app['config']->set('eloquent_sf.routes.login.redirectTo', '/dashboard');
    }

    public function test_the_login_route_starts_the_oauth_flow(): void
    {
        $this->get('/login/salesforce')->assertOk();

        $this->salesforce->assertAuthenticated();
    }

    public function test_the_callback_route_redirects_to_the_configured_location(): void
    {
        $this->get('/login/salesforce/callback')->assertRedirect('/dashboard');
    }

    public function test_the_sync_webhook_rejects_requests_without_the_secret(): void
    {
        $this->post('/api/syncObject/'.self::LEAD)->assertForbidden();

        $this->post('/api/syncObject/'.self::LEAD, [], [VerifySyncSecret::HEADER => 'wrong'])->assertForbidden();

        $this->salesforce->assertNothingSent();
    }

    public function test_the_sync_webhook_rejects_everything_when_no_secret_is_configured(): void
    {
        config(['eloquent_sf.routes.sync.secret' => null]);

        $this->post('/api/syncObject/'.self::LEAD, [], [VerifySyncSecret::HEADER => ''])->assertForbidden();
    }

    public function test_the_sync_webhook_syncs_the_linked_local_models(): void
    {
        $this->requireSqlite();

        Schema::create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email')->unique();
            $table->string('salesforce')->nullable();
            $table->string('name');
            $table->string('company');
            $table->timestamps();
        });

        $this->salesforce->records('Lead', [[
            'Id' => self::LEAD,
            'Email' => 'new@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'Lovelace',
            'Company' => 'Analytical',
            'LastModifiedDate' => now()->addHour()->format('Y-m-d\TH:i:s.vO'),
        ]]);

        $local = TestModel::withoutEvents(fn () => TestModel::forceCreate([
            'email' => 'old@example.com',
            'name' => 'Ada Lovelace',
            'company' => 'Analytical',
            'salesforce' => self::LEAD,
        ]));

        $this->post('/api/syncObject/'.self::LEAD, [], [VerifySyncSecret::HEADER => 's3cret'])
            ->assertOk()
            ->assertExactJson(['synced' => 1]);

        $this->assertSame('new@example.com', $local->refresh()->email);
    }
}
