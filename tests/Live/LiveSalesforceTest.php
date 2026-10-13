<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Live;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use SETechSolutions\EloquentSalesForce\Database\SOQLBatch;
use SETechSolutions\EloquentSalesForce\Exceptions\MalformedQueryException;
use SETechSolutions\EloquentSalesForce\Exceptions\RestAPIException;
use SETechSolutions\EloquentSalesForce\Facades\SObjects;
use SETechSolutions\EloquentSalesForce\Http\Middleware\VerifySyncSecret;
use SETechSolutions\EloquentSalesForce\SalesForceObject;
use SETechSolutions\EloquentSalesForce\ServiceProvider;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestLead;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestModel;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestObserver;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestTask;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestUser;

/**
 * Runs against a real Salesforce org and creates and deletes Lead and Task
 * records there. Only ever point it at a Developer Edition org or a sandbox.
 *
 * It is not part of the default test suite. Provide SF_CONSUMER_KEY,
 * SF_CONSUMER_SECRET, SF_USERNAME, SF_PASSWORD and optionally SF_LOGIN_URL,
 * then run: vendor/bin/phpunit --testsuite Live
 *
 * The org needs two custom Lead fields: Custom_Text_Field__c (Text) and
 * Custom_Date_Field__c (Date).
 */
#[Group('live')]
class LiveSalesforceTest extends TestCase
{
    use WithFaker;

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:WRAf0EDpFqwpbS829xKy2MGEkcJxIEmMrwFIZbGxIqE=');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('eloquent_sf.logging', false);
        $app['config']->set('eloquent_sf.syncTwoWay', true);
        $app['config']->set('eloquent_sf.syncPriority', 'local');
        $app['config']->set('eloquent_sf.syncTwoWayModels', [TestModel::class]);
        $app['config']->set('eloquent_sf.routes.sync.secret', 'live-test-secret');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        if (! getenv('SF_CONSUMER_KEY') || ! getenv('SF_USERNAME')) {
            $this->markTestSkipped('Salesforce credentials are not configured.');
        }

        parent::setUp();

        TestLead::observe(TestObserver::class);

        TestLead::truncate();
        TestTask::truncate();
    }

    private $lead;

    public function test_string_type()
    {
        $lead = TestLead::create([
            'FirstName' => 'Rob',
            'LastName' => 'Lester',
            'Company' => 'Test',
            'Email' => 'test@test.com',
            'Custom_Text_Field__c' => '009',
        ]);

        $lead->refresh();

        $this->assertEquals('009', $lead->Custom_Text_Field__c);
    }

    public function test_for_update()
    {
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $this->expectException(RestAPIException::class);
        TestLead::where('Id', 'test')->get();

        $this->expectException(MalformedQueryException::class);
        TestLead::select('Id')->limit(1)->lockForUpdate()->get();

    }

    public function test_facade_versions()
    {
        $this->assertFalse(cache()->has('sfdc_versions'));
        $versions = SObjects::versions();
        $this->assertTrue(cache()->has('sfdc_versions'));
    }

    public function test_dirty_and_changes()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $this->assertFalse($lead->isDirty());

        $lead->FirstName = 'Testing';

        $this->assertTrue($lead->isDirty());

        $this->assertCount(1, $lead->getDirty());

        $lead->save();

        $this->assertCount(1, $lead->getChanges());
    }

    public function test_simple_object()
    {
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $test = SalesForceObject::select('Id')
            ->from('Lead')
            ->where('Email', 'test@test.com')
            ->first();

        $this->assertEquals($lead->Id, $test->Id);
    }

    public function test_syncs_models_to_salesforce()
    {
        Schema::create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email')->unique();
            $table->string('salesforce')->nullable();
            $table->string('name');
            $table->string('company');
            $table->timestamps();
        });

        $test = TestModel::create([
            'email' => 'test@test.com',
            'name' => 'Rob Lester',
            'company' => 'Test Company',
        ]);

        $this->assertCount(1, TestModel::get());
        $this->assertCount(1, TestLead::where('Email', 'test@test.com')->get());

        $test->update([
            'email' => 'test2@test.com',
        ]);

        $object = TestLead::where('Email', 'test2@test.com')->first();

        $this->assertEquals('test2@test.com', $test->fresh()->email);
        $this->assertEquals('test2@test.com', $object->Email);

        config([
            'eloquent_sf.syncTwoWay' => true,
            'eloquent_sf.syncPriority' => 'local',
        ]);

        sleep(2);

        $object->update([
            'Email' => 'test3@test.com',
        ]);

        $test->syncWithSalesforce();

        $test->refresh();

        $this->assertEquals('test3@test.com', $test->email);

        $test4 = TestModel::create([
            'email' => 'test4@test.com',
            'name' => 'Rob Lester',
            'company' => 'Test Company',
        ]);

        $this->assertNotNull(TestLead::where('Email', 'test4@test.com')->first());
        $this->assertEquals('Rob', TestLead::where('Email', 'test4@test.com')->first()->FirstName);

        $object = TestLead::where('Email', 'test4@test.com')->first();

        $object->update([
            'Email' => 'test5@test.com',
        ]);

        $test4->email = 'test6@test.com';

        $test4->syncWithSalesforce();

        $this->assertEquals($test4->email, $object->refresh()->Email);

    }

    public function test_sync_command()
    {
        config([
            'eloquent_sf.syncTwoWay' => true,
            'eloquent_sf.syncPriority' => 'local',
            'eloquent_sf.syncTwoWayModels' => [
                TestModel::class,
            ],
        ]);

        Schema::create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email')->unique();
            $table->string('salesforce')->nullable();
            $table->string('name');
            $table->string('company');
            $table->timestamps();
        });

        $test = TestModel::create([
            'email' => 'test@test.com',
            'name' => 'Rob Test',
            'company' => 'Test Company',
        ]);

        sleep(2);

        $object = TestLead::find($test->refresh()->salesforce);

        $this->assertNotNull($object);

        sleep(2);

        $object->update([
            'Email' => 'test2@test.com',
        ]);

        Artisan::call('salesforce:sync');

        $this->assertEquals($object->Email, $test->refresh()->email);

        $response = $this->post('api/syncObject/'.$object->Id, [], [VerifySyncSecret::HEADER => 'live-test-secret']);

        $response->assertStatus(200);
    }

    public function test_object_create()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $lead = TestLead::where('Email', $email)->first();

        $this->assertNotNull($lead);
        $this->assertEquals($lead->Email, $email);
        // $this->assertEquals($lead->Phone, '1231231234');
        $this->assertEquals($lead->Company, 'Test Company');

        TestLead::truncate();

        $lead = TestLead::firstOrCreate(
            ['Email' => $email],
            ['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test']
        );

        $this->assertTrue($lead->wasRecentlyCreated);
    }

    public function test_object_mass()
    {
        $collection = collect([]);
        for ($i = 0; $i < 10; $i++) {
            $email = strtolower(Str::random(10).'@test.com');
            $collection->push(new TestLead(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]));
        }
        $results = TestLead::insert($collection);

        $ids = $results->pluck('Id');
        $results = TestLead::whereIn('Id', $ids)->get();

        $this->assertCount(10, $results);

        $results = $results->slice(0, 3)->map(function ($lead) {
            $lead->Company = 'Test 2';

            return $lead;
        });

        SObjects::update($results);

        $lead = TestLead::find($results->first()->Id);

        $this->assertEquals($lead->Company, 'Test 2');

        $batch = new SOQLBatch;

        $batch->query(TestLead::where('Company', 'Test'));

        $batch->query(TestLead::where('Company', 'Test 2'));

        $results = $batch->run();

        $this->assertCount(7, $results->get('TestLead_0'));
        $this->assertCount(3, $results->get('TestLead_1'));

        TestLead::truncate();

        $this->assertCount(0, TestLead::all());
    }

    public function test_object_update()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $lead->update(['FirstName' => 'Robert']);

        $lead = TestLead::where('Email', $email)->first();

        $this->assertEquals($lead->FirstName, 'Robert');

        $lead = TestLead::updateOrCreate(
            ['Email' => $email],
            ['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test']
        );

        $this->assertEquals($lead->FirstName, 'Rob');
        $this->assertFalse($lead->wasRecentlyCreated);

        $lead->delete();

        $lead = TestLead::updateOrCreate(
            ['Email' => $email],
            ['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test']
        );

        $this->assertTrue($lead->wasRecentlyCreated);
    }

    public function test_where_basic()
    {
        TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $leads = TestLead::where('FirstName', 'not like', 'xxxxxxxxxxxxx')->get();
        $this->assertTrue($leads->count() > 0);

        $this->assertInstanceOf(Carbon::class, $leads->first()->CreatedDate);

        $int = 123;
        $lead = TestLead::select('Id', 'CreatedDate')->where('LastName', 'August')->where('Email', (string) $int)->first();

        $this->assertNull($lead);

        $user = TestUser::first();
        $this->assertNotNull($user);
    }

    public function test_where_boolean()
    {
        TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $leads = TestLead::where('DoNotCall', false)->limit(5)->get();
        $this->assertTrue($leads->count() > 0);
    }

    public function test_where_in()
    {

        TestLead::insert(collect([
            new TestLead([
                'Email' => strtolower(Str::random(10).'@test.com'),
                'FirstName' => 'Kathy',
                'LastName' => 'Test',
                'Company' => 'TestCo',
            ]),
            new TestLead([
                'Email' => strtolower(Str::random(10).'@test.com'),
                'FirstName' => 'Betty',
                'LastName' => 'Test',
                'Company' => 'TestCo',
            ]),
        ]));

        $leads = TestLead::whereIn('FirstName', ['Kathy', 'Betty'])->get();
        $this->assertCount(2, $leads);
    }

    public function test_where_date()
    {
        TestLead::create([
            'FirstName' => 'Rob',
            'LastName' => 'Lester',
            'Company' => 'Test',
            'Email' => 'test@test.com',
            'Custom_Date_Field__c' => now()->subDay(),
        ]);

        /*$query = TestLead::query()
            ->where('CreatedDate', '>=', today())
            ->where('Email', 'test@test.com')
            ->where('Id', 'fffff')
            ->toSql();

        dd($query);*/

        $leads = TestLead::where('CreatedDate', '>=', today())->where('Id', '<>', '0030000000Db7DuAAJ')->get();
        $this->assertTrue($leads->count() > 0);

        $lead = TestLead::where('Email', 'test@test.com')->first();

        $now = now();
        $lead->update([
            'Custom_Date_Field__c' => $now,
            'Company' => 'Test Co',
        ]);
        $lead = TestLead::select('Custom_Date_Field__c', 'Id')
            ->where('Email', 'test@test.com')
            ->whereDate('Custom_Date_Field__c', '>=', today())
            ->first();

        TestLead::whereTime('CreatedDate', now())->get();

        $this->assertTrue($now->startOfDay()->eq($lead->Custom_Date_Field__c));
    }

    public function test_or_where()
    {
        TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $leads = TestLead::where('FirstName', 'not like', 'xxxxxxxxxxxxx')->orWhere('Owner.UserRole.Name', 'like', 'yyyyyyyyyy%')->limit(5)->get();
        $this->assertTrue($leads->count() > 0);
    }

    public function test_object_delete()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);
        $lead->delete();
        $lead = TestLead::where('Email', $email)->get();
        $this->assertCount(0, $lead);
    }

    public function test_relationships()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $task = $lead->tasks()->create(['Subject' => 'TestTask']);

        $lead = $task->lead;

        $this->assertCount(1, $lead->tasks);

        $task->delete();

        $lead->delete();

        // dd(SObjects::queryHistory());

    }

    // TODO
    /*public function testExists()
    {
        $email = strtolower(Str::random(10) . '@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $exists = TestLead::where('LastName', 'Lester')->exists();

        $this->assertTrue($exists);

        $lead->delete();
    }*/

    public function test_joins()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $task = $lead->tasks()->create(['Subject' => 'TestTask']);

        $joined = TestLead::join('Task', 'WhoId')->where('Email', $email)->first();

        $this->assertCount(1, $joined->Tasks['records']);

        $task->delete();

        $lead->delete();
    }

    public function test_paginate()
    {
        TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $pageone = TestLead::paginate(3);

        $this->assertTrue($pageone->count() > 0);

    }

    public function test_get_picklist_values()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        $statuses = $lead->getPicklistValues('Status');

        $this->assertNotNull($statuses);
        $this->assertTrue($statuses->count() > 0);

        $statuses = TestLead::getPicklistValues('Status');

        $this->assertNotNull($statuses);
        $this->assertTrue($statuses->count() > 0);

    }

    public function test_mass_delete()
    {
        $email = strtolower(Str::random(10).'@test.com');
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => $email]);

        TestLead::select('Id')->delete();

        $this->assertCount(0, TestLead::all());

    }

    public function test_facade_funcs()
    {
        // SObjects::authenticate();

        TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $query = \Forrest::query('select Id from Lead limit 1');

        $object = SObjects::object($query['records'][0]);
        $this->assertInstanceOf(SalesForceObject::class, $object);

        $testLeadFields = SObjects::describe('Product2');

        $this->assertNotNull($testLeadFields);

        $convertedId = SObjects::convert('5003000000D8cuI');
        $this->assertEquals('5003000000D8cuIAAR', $convertedId);

        TestLead::truncate();

    }

    public function test_query_literals()
    {
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $results = TestLead::onlyTrashed()->where('CreatedDate', 'THIS_WEEK')->get();

        $this->assertTrue($results->count() > 0);
    }

    public function test_soft_deletes()
    {
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);
        $id = $lead->Id;

        $allLeads = TestLead::get();
        $this->assertCount(1, $allLeads);

        $this->assertFalse($lead->trashed());

        $lead->delete();

        $allLeads = TestLead::get();
        $this->assertCount(0, $allLeads);

        $allLeads = TestLead::withTrashed()->get();
        // dd($allLeads);
        $this->assertTrue($allLeads->count() > 0);

        $deleted = TestLead::withTrashed()->where('IsDeleted', true)->first();
        $idFind = TestLead::onlyTrashed()->where('Id', $id)->first();

        $this->assertTrue($deleted->trashed());
        $this->assertEquals($idFind->Id, $id);

        $this->expectException(\Exception::class);
        $deleted->restore();

    }

    public function test_cursor_with_soft_deletes()
    {
        $leadOne = TestLead::create(['FirstName' => $this->faker->firstName(), 'LastName' => $this->faker->lastName(), 'Company' => $this->faker->company(), 'Email' => $this->faker->email()]);

        $leadTwo = TestLead::create(['FirstName' => $this->faker->firstName(), 'LastName' => $this->faker->lastName(), 'Company' => $this->faker->company(), 'Email' => $this->faker->email()]);

        $leadsByGetCount = TestLead::get()->count();
        $leadsByCursorCount = TestLead::cursor()->count();
        $this->assertEquals($leadsByGetCount, $leadsByCursorCount);

        $allLeadsByGetCount = TestLead::withTrashed()->get()->count();
        $allLeadsByCursorCount = TestLead::withTrashed()->cursor()->count();
        $this->assertEquals($allLeadsByGetCount, $allLeadsByCursorCount);

        $onlyTrashedLeadsByGetCount = TestLead::onlyTrashed()->get()->count();
        $onlyTrashedLeadsByCursorCount = TestLead::onlyTrashed()->cursor()->count();
        $this->assertEquals($onlyTrashedLeadsByGetCount, $onlyTrashedLeadsByCursorCount);

        $leadOne->delete();

        $newLeadsByCursorCount = TestLead::cursor()->count();
        $this->assertEquals($leadsByCursorCount - 1, $newLeadsByCursorCount);

        $newAllLeadsByCursorCount = TestLead::withTrashed()->cursor()->count();
        $this->assertEquals($allLeadsByCursorCount, $newAllLeadsByCursorCount);

        $newOnlyTrashedLeadsByCursorCount = TestLead::onlyTrashed()->cursor()->count();
        $this->assertEquals($onlyTrashedLeadsByCursorCount + 1, $newOnlyTrashedLeadsByCursorCount);

    }

    /*public function testReplicate()
    {
        $lead = TestLead::create(['FirstName' => 'Rob', 'LastName' => 'Lester', 'Company' => 'Test', 'Email' => 'test@test.com']);

        $leadTwo = $lead->replicate()->fill([
            'Email' => 'test2@test.com'
        ])->save();

        $this->assertTrue($leadTwo->wasRecentlyCreated);

    }*/
}
