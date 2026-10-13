<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Feature;

use Illuminate\Support\Carbon;
use SETechSolutions\EloquentSalesForce\Exceptions\MalformedQueryException;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestLead;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestObserver;
use SETechSolutions\EloquentSalesForce\Tests\TestCase;

class ModelPersistenceTest extends TestCase
{
    private const ID = '00Q000000000001AAA';

    public function test_create_posts_the_attributes_and_stores_the_new_id(): void
    {
        $lead = TestLead::create(['FirstName' => 'Ada', 'LastName' => 'Lovelace', 'Company' => 'Analytical']);

        $this->assertSame('001000000000001AAA', $lead->Id);
        $this->assertTrue($lead->exists);
        $this->assertTrue($lead->wasRecentlyCreated);
        $this->assertFalse($lead->isDirty());

        $this->salesforce->assertCreated('Lead', function (array $attributes) {
            return $attributes === [
                'FirstName' => 'Ada',
                'LastName' => 'Lovelace',
                'Company' => 'Analytical',
                'Phone' => '1231231234',
            ];
        });
    }

    public function test_model_events_and_observers_fire_on_create(): void
    {
        TestLead::observe(TestObserver::class);

        $created = false;
        TestLead::created(function () use (&$created) {
            $created = true;
        });

        TestLead::create(['LastName' => 'Lovelace', 'Company' => 'Analytical']);

        $this->assertTrue($created);
        $this->salesforce->assertCreated('Lead', ['Company' => 'Test Company', 'Phone' => '1231231234']);
    }

    public function test_a_creating_listener_can_cancel_the_insert(): void
    {
        TestLead::creating(fn () => false);

        $lead = new TestLead(['LastName' => 'Lovelace']);

        $this->assertFalse($lead->save());
        $this->salesforce->assertNothingWritten();
    }

    public function test_update_patches_only_the_changed_fields(): void
    {
        $lead = $this->existingLead();

        $lead->update(['FirstName' => 'Augusta']);

        $this->salesforce->assertUpdated('Lead', self::ID, fn (array $attributes) => $attributes === ['FirstName' => 'Augusta']);
        $this->assertSame(['FirstName' => 'Augusta'], $lead->getChanges());
        $this->assertFalse($lead->isDirty());
    }

    public function test_saving_an_unchanged_model_sends_nothing(): void
    {
        $lead = $this->existingLead();

        $this->assertTrue($lead->save());
        $this->salesforce->assertNothingWritten();
    }

    public function test_read_only_fields_and_the_id_are_never_written(): void
    {
        $lead = new class(['Id' => self::ID, 'LastName' => 'Lovelace', 'Formula__c' => 'x']) extends TestLead
        {
            protected $readonly = ['Formula__c'];
        };

        $lead->save();

        $this->salesforce->assertUpdated('Lead', self::ID, fn (array $attributes) => $attributes === ['LastName' => 'Lovelace']);
    }

    public function test_dates_are_written_in_the_format_salesforce_expects(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-03-10 08:15:30', 'UTC'));

        TestLead::create([
            'LastName' => 'Lovelace',
            'Custom_Date_Field__c' => now(),
            'CreatedDate' => now(),
        ]);

        $this->salesforce->assertCreated('Lead', [
            'Custom_Date_Field__c' => '2024-03-10',
            'CreatedDate' => '2024-03-10T08:15:30.000+0000',
        ]);
    }

    public function test_dates_are_read_as_carbon_instances(): void
    {
        $lead = $this->existingLead([
            'CreatedDate' => '2024-04-18T19:49:54.000+0000',
            'Custom_Date_Field__c' => '2024-04-01',
        ]);

        $this->assertInstanceOf(Carbon::class, $lead->CreatedDate);
        $this->assertSame('2024-04-18 19:49:54', $lead->CreatedDate->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-04-01', $lead->Custom_Date_Field__c->format('Y-m-d'));
    }

    public function test_custom_headers_are_sent_with_writes(): void
    {
        $captured = [];

        $this->salesforce->resource('sobjects', function ($path, $options = []) use (&$captured) {
            $captured[] = $options;

            return ['id' => self::ID, 'success' => true, 'errors' => []];
        });

        $lead = new TestLead(['LastName' => 'Lovelace']);
        $lead->custom_headers = ['Sforce-Auto-Assign' => 'FALSE'];
        $lead->save();

        $lead->update(['LastName' => 'King']);

        $this->assertSame(['Sforce-Auto-Assign' => 'FALSE'], $captured[0]['headers']);
        $this->assertSame(['Sforce-Auto-Assign' => 'FALSE'], $captured[1]['headers']);
    }

    public function test_delete_removes_the_record_and_fires_events(): void
    {
        $lead = $this->existingLead();

        $deleted = false;
        TestLead::deleted(function () use (&$deleted) {
            $deleted = true;
        });

        $this->assertTrue($lead->delete());
        $this->assertFalse($lead->exists);
        $this->assertTrue($deleted);
        $this->salesforce->assertDeleted(self::ID);
    }

    public function test_delete_returns_false_when_salesforce_refuses(): void
    {
        $this->salesforce->resource('sobjects', fn () => throw new \RuntimeException('ENTITY_IS_DELETED'));

        $lead = $this->existingLead();

        $this->assertFalse($lead->delete());
        $this->assertTrue($lead->exists);
    }

    public function test_first_or_create_and_update_or_create(): void
    {
        $created = TestLead::firstOrCreate(['Email' => 'ada@example.com'], ['LastName' => 'Lovelace']);

        $this->assertTrue($created->wasRecentlyCreated);
        $this->salesforce->assertCreated('Lead', ['Email' => 'ada@example.com', 'LastName' => 'Lovelace']);

        $this->salesforce->records('Lead', [['Id' => self::ID, 'Email' => 'ada@example.com', 'LastName' => 'Lovelace']]);

        $updated = TestLead::updateOrCreate(['Email' => 'ada@example.com'], ['LastName' => 'King']);

        $this->assertFalse($updated->wasRecentlyCreated);
        $this->salesforce->assertUpdated('Lead', self::ID, ['LastName' => 'King']);
    }

    public function test_refresh_reloads_the_record(): void
    {
        $lead = $this->existingLead();

        $this->salesforce->records('Lead', [['Id' => self::ID, 'LastName' => 'King']]);

        $this->assertSame('King', $lead->refresh()->LastName);
    }

    public function test_exists_runs_a_minimal_query(): void
    {
        $this->assertFalse(TestLead::where('Company', 'Acme')->exists());
        $this->assertTrue(TestLead::where('Company', 'Acme')->doesntExist());

        $this->salesforce->records('Lead', [['Id' => self::ID]]);

        $this->assertTrue(TestLead::where('Company', 'Acme')->orderBy('LastName')->exists());

        $this->salesforce->assertQueried("select Id from Lead where Company = 'Acme' limit 1");
    }

    public function test_salesforce_errors_become_typed_exceptions(): void
    {
        $this->salesforce->whenQuery('*', new MalformedQueryException('unexpected token'));

        $this->expectException(MalformedQueryException::class);

        TestLead::select('Id')->get();
    }

    public function test_trashed_reflects_is_deleted_and_restore_is_unsupported(): void
    {
        $this->assertFalse($this->existingLead()->trashed());

        $deleted = $this->existingLead(['IsDeleted' => true]);

        $this->assertTrue($deleted->trashed());

        $this->expectException(\Exception::class);

        $deleted->restore();
    }

    public function test_with_trashed_uses_query_all(): void
    {
        TestLead::select('Id')->withTrashed()->get();
        TestLead::select('Id')->get();

        $types = $this->salesforce->recorded()->pluck('type')->all();

        $this->assertSame(['queryAll', 'query'], $types);
    }

    public function test_cursor_streams_records(): void
    {
        $this->salesforce->records('Lead', [['Id' => self::ID], ['Id' => '00Q000000000002AAA']]);

        $ids = TestLead::cursor()->map->Id->all();

        $this->assertSame([self::ID, '00Q000000000002AAA'], $ids);
    }

    public function test_web_link_points_at_the_instance(): void
    {
        $this->assertSame(
            'https://fake.my.salesforce.com/'.self::ID,
            $this->existingLead()->web_link
        );
    }

    public function test_models_survive_queue_serialization(): void
    {
        $this->salesforce->records('Lead', [['Id' => self::ID, 'LastName' => 'Lovelace']]);

        $lead = TestLead::select('Id', 'LastName')->first();

        /** @var TestLead $restored */
        $restored = unserialize(serialize($lead));

        $this->assertSame(self::ID, $restored->Id);
        $this->assertSame('Lead', $restored->getTable());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function existingLead(array $attributes = []): TestLead
    {
        $this->salesforce->records('Lead', [
            ['Id' => self::ID, 'FirstName' => 'Ada', 'LastName' => 'Lovelace'] + $attributes,
        ]);

        $lead = TestLead::find(self::ID);

        $this->assertInstanceOf(TestLead::class, $lead);

        return $lead;
    }
}
