<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Feature;

use Illuminate\Support\Carbon;
use SETechSolutions\EloquentSalesForce\Database\SOQLBatch;
use SETechSolutions\EloquentSalesForce\Facades\SObjects;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestLead;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestTask;
use SETechSolutions\EloquentSalesForce\Tests\TestCase;

class BulkOperationsTest extends TestCase
{
    public function test_bulk_insert_returns_the_models_with_their_ids(): void
    {
        $leads = collect([
            new TestLead(['LastName' => 'One', 'Custom_Date_Field__c' => Carbon::parse('2024-03-10')]),
            new TestLead(['LastName' => 'Two']),
        ]);

        $inserted = TestLead::insert($leads);

        $this->assertCount(2, $inserted);
        $this->assertSame(['001000000000001AAA', '001000000000002AAA'], $inserted->pluck('Id')->all());
        $this->assertSame('Two', $inserted[1]->LastName);
        $this->assertTrue($inserted[0]->exists);
        $this->assertFalse($inserted[0]->isDirty());

        $this->salesforce->assertCreated('Lead', fn (array $attributes) => $attributes === [
            'LastName' => 'One',
            'Custom_Date_Field__c' => '2024-03-10',
        ]);
    }

    public function test_bulk_insert_accepts_plain_arrays(): void
    {
        $inserted = TestLead::insert([['LastName' => 'One'], ['LastName' => 'Two']]);

        $this->assertCount(2, $inserted);
        $this->assertInstanceOf(TestLead::class, $inserted->first());
    }

    public function test_bulk_insert_accepts_a_single_attribute_array(): void
    {
        $inserted = TestLead::insert(['LastName' => 'One', 'Company' => 'Acme']);

        $this->assertCount(1, $inserted);
        $this->salesforce->assertCreated('Lead', ['LastName' => 'One', 'Company' => 'Acme']);
    }

    public function test_bulk_insert_is_split_into_requests_of_at_most_200_records(): void
    {
        $requests = 0;

        $this->salesforce->resource('composite', function ($path, $options) use (&$requests) {
            $requests++;

            $this->assertLessThanOrEqual(200, count($options['body']['records']));

            return ['hasErrors' => false, 'results' => array_map(
                fn ($record) => ['referenceId' => $record['attributes']['referenceId'], 'id' => '00Q'.str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT).'AAA'],
                $options['body']['records']
            )];
        });

        config(['eloquent_sf.batch.insert.size' => 5000]);

        $inserted = TestLead::insert(array_fill(0, 450, ['LastName' => 'Bulk']));

        $this->assertSame(3, $requests);
        $this->assertCount(450, $inserted);
    }

    public function test_facade_update_sends_only_dirty_fields(): void
    {
        $this->salesforce->records('Lead', [
            ['Id' => '00Q000000000001AAA', 'Company' => 'Old', 'LastName' => 'One'],
            ['Id' => '00Q000000000002AAA', 'Company' => 'Old', 'LastName' => 'Two'],
        ]);

        $leads = TestLead::all()->each(function (TestLead $lead) {
            $lead->Company = 'New';
        });

        $results = SObjects::update($leads);

        $this->assertCount(2, $results);
        $this->salesforce->assertUpdated('Lead', '00Q000000000001AAA', fn (array $attributes) => $attributes === ['Company' => 'New']);
        $this->salesforce->assertMassUpdate($leads);
    }

    public function test_query_update_updates_every_matching_record(): void
    {
        $this->salesforce->records('Lead', [
            ['Id' => '00Q000000000001AAA'],
            ['Id' => '00Q000000000002AAA'],
        ]);

        $updated = TestLead::where('Company', 'Old')->update(['Company' => 'New', 'Custom_Date_Field__c' => Carbon::parse('2024-03-10')]);

        $this->assertSame(2, $updated);
        $this->salesforce->assertQueried("select Id from Lead where Company = 'Old'");
        $this->salesforce->assertUpdated('Lead', '00Q000000000002AAA', fn (array $attributes) => $attributes === [
            'Company' => 'New',
            'Custom_Date_Field__c' => '2024-03-10',
        ]);
    }

    public function test_query_update_without_matches_sends_no_write(): void
    {
        $this->assertSame(0, TestLead::where('Company', 'Nobody')->update(['Company' => 'New']));

        $this->salesforce->assertNothingWritten();
    }

    public function test_query_delete_deletes_every_matching_record_in_chunks(): void
    {
        $this->salesforce->records('Lead', array_map(
            fn ($i) => ['Id' => sprintf('00Q%012dAAA', $i)],
            range(1, 250)
        ));

        $deleted = TestLead::where('Company', 'Old')->delete();

        $this->assertSame(250, $deleted);
        $this->assertCount(250, $this->salesforce->recorded('delete'));
        $this->salesforce->assertDeleted('00Q000000000250AAA');
        $this->salesforce->assertQueried("select Id from Lead where Company = 'Old'");
    }

    public function test_truncate_deletes_everything(): void
    {
        $this->salesforce->records('Lead', [['Id' => '00Q000000000001AAA']]);

        TestLead::truncate();

        $this->salesforce->assertDeleted('00Q000000000001AAA');
    }

    public function test_batched_queries_run_in_one_request_and_hydrate_models(): void
    {
        $this->salesforce->records('Lead', [['Id' => '00Q000000000001AAA', 'LastName' => 'One']]);
        $this->salesforce->records('Task', [['Id' => '00T000000000001AAA', 'Subject' => 'Call']]);

        TestLead::where('Company', 'Acme')->batch('leads');
        TestTask::select('Id', 'Subject')->batch();

        $batch = SObjects::runBatch();

        $this->assertInstanceOf(SOQLBatch::class, $batch);
        $this->assertInstanceOf(TestLead::class, $batch->get('leads')->first());
        $this->assertTrue($batch->get('leads')->first()->exists);
        $this->assertFalse($batch->get('leads')->first()->isDirty());
        $this->assertSame('Call', $batch->results('TestTask_1')->first()->Subject);
        $this->assertCount(0, SObjects::getBatch());

        $this->salesforce->assertQueried('select Id, Subject from Task');
    }

    public function test_batch_sub_requests_carry_the_api_version(): void
    {
        $urls = [];

        $this->salesforce->resource('composite', function ($path, $options) use (&$urls) {
            $urls = array_column($options['body']['batchRequests'], 'url');

            return ['hasErrors' => false, 'results' => [['statusCode' => 400, 'result' => [['errorCode' => 'MALFORMED_QUERY']]]]];
        });

        config(['eloquent_sf.forrest.version' => '61.0']);

        $batch = (new SOQLBatch)->query(TestLead::select('Id'))->run();

        $this->assertSame(['v61.0/query?q='.urlencode('select Id from Lead')], $urls);
        $this->assertSame(400, $batch->get('TestLead_0')->statusCode);
    }
}
