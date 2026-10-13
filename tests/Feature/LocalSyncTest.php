<?php

namespace Lester\EloquentSalesForce\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lester\EloquentSalesForce\Tests\Fixtures\TestModel;
use Lester\EloquentSalesForce\Tests\TestCase;

class LocalSyncTest extends TestCase
{
    private const LEAD = '00Q000000000001AAA';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireSqlite();

        Schema::create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email')->unique();
            $table->string('salesforce')->nullable();
            $table->string('name');
            $table->string('company');
            $table->timestamps();
        });
    }

    public function test_creating_a_local_model_creates_the_salesforce_record(): void
    {
        $model = TestModel::create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'company' => 'Analytical']);

        $this->assertSame('001000000000001AAA', $model->salesforce);
        $this->assertSame('001000000000001AAA', $model->fresh()->salesforce);

        $this->salesforce->assertCreated('Lead', fn (array $attributes) => $attributes === [
            'Email' => 'ada@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'Lovelace',
            'Company' => 'Analytical',
        ]);
        $this->salesforce->assertQueryCount(0);
    }

    public function test_updating_a_local_model_patches_only_what_changed(): void
    {
        $model = $this->linkedModel();

        $this->salesforce->records('Lead', [[
            'Id' => self::LEAD,
            'Email' => 'ada@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'Lovelace',
            'Company' => 'Analytical',
        ]]);

        $model->update(['email' => 'countess@example.com']);

        $this->salesforce->assertQueried("select Id, Email, FirstName, LastName, Company from Lead where (Id = '".self::LEAD."') limit 1");
        $this->salesforce->assertUpdated('Lead', self::LEAD, fn (array $attributes) => $attributes === ['Email' => 'countess@example.com']);
    }

    public function test_two_way_sync_pulls_newer_salesforce_values(): void
    {
        config(['eloquent_sf.syncTwoWay' => true]);

        $model = $this->linkedModel();

        $this->salesforce->records('Lead', [[
            'Id' => self::LEAD,
            'Email' => 'new@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'King',
            'Company' => 'Analytical',
            'LastModifiedDate' => now()->addHour()->format('Y-m-d\TH:i:s.vO'),
        ]]);

        $model->syncWithSalesforce();

        $this->assertSame('new@example.com', $model->fresh()->email);
        $this->assertSame('Ada King', $model->fresh()->name);
    }

    public function test_two_way_sync_ignores_older_salesforce_values(): void
    {
        config(['eloquent_sf.syncTwoWay' => true]);

        $model = $this->linkedModel();

        $this->salesforce->records('Lead', [[
            'Id' => self::LEAD,
            'Email' => 'stale@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'Lovelace',
            'Company' => 'Analytical',
            'LastModifiedDate' => now()->subDay()->format('Y-m-d\TH:i:s.vO'),
        ]]);

        $model->syncWithSalesforce();

        $this->assertSame('ada@example.com', $model->fresh()->email);
    }

    public function test_the_sync_command_syncs_every_linked_model(): void
    {
        config([
            'eloquent_sf.syncTwoWay' => true,
            'eloquent_sf.syncTwoWayModels' => [TestModel::class],
        ]);

        $linked = $this->linkedModel();

        TestModel::withoutEvents(fn () => TestModel::forceCreate([
            'email' => 'unlinked@example.com',
            'name' => 'Not Linked',
            'company' => 'None',
        ]));

        $this->salesforce->records('Lead', [[
            'Id' => self::LEAD,
            'Email' => 'new@example.com',
            'FirstName' => 'Ada',
            'LastName' => 'Lovelace',
            'Company' => 'Analytical',
            'LastModifiedDate' => now()->addHour()->format('Y-m-d\TH:i:s.vO'),
        ]]);

        $this->artisan('salesforce:sync')->assertSuccessful();

        $this->assertSame('new@example.com', $linked->fresh()->email);
        $this->assertSame('unlinked@example.com', TestModel::where('name', 'Not Linked')->first()->email);

        $this->artisan('db:sync')->assertSuccessful();
    }

    private function linkedModel(): TestModel
    {
        return TestModel::withoutEvents(fn () => TestModel::forceCreate([
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'company' => 'Analytical',
            'salesforce' => self::LEAD,
        ]));
    }
}
