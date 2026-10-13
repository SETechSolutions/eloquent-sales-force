<?php

namespace Lester\EloquentSalesForce\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lester\EloquentSalesForce\Facades\SObjects;
use Lester\EloquentSalesForce\SalesForceObject;
use Lester\EloquentSalesForce\Tests\Fixtures\TestLead;
use Lester\EloquentSalesForce\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SObjectsFacadeTest extends TestCase
{
    #[DataProvider('ids')]
    public function test_it_converts_15_character_ids_to_18_characters(string $short, string $long): void
    {
        $this->assertSame($long, SObjects::convert($short));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ids(): array
    {
        return [
            'upstream example' => ['5003000000D8cuI', '5003000000D8cuIAAR'],
            'no uppercase letters' => ['001000000000001', '001000000000001AAA'],
            'uppercase A in first position' => ['A00000000000000', 'A00000000000000BAA'],
            'uppercase T' => ['0000T0000000000', '0000T0000000000QAA'],
            'all uppercase' => ['ABCDEFGHIJKLMNO', 'ABCDEFGHIJKLMNO555'],
            'mixed case' => ['a0B5e00000CdEfG', 'a0B5e00000CdEfGEAV'],
        ];
    }

    public function test_convert_leaves_other_lengths_alone(): void
    {
        $this->assertSame('5003000000D8cuIAAR', SObjects::convert('5003000000D8cuIAAR'));
        $this->assertSame('short', SObjects::convert('short'));
    }

    public function test_it_recognises_salesforce_ids(): void
    {
        $this->assertTrue(SObjects::isSalesForceId('5003000000D8cuI'));
        $this->assertTrue(SObjects::isSalesForceId('5003000000D8cuIAAR'));
        $this->assertFalse(SObjects::isSalesForceId('5003000000D8cuIA'));
        $this->assertFalse(SObjects::isSalesForceId('5003000000D8cu!'));
        $this->assertFalse(SObjects::isSalesForceId(123456789012345));
    }

    public function test_it_reads_picklist_values(): void
    {
        $this->salesforce->describes('Lead', ['fields' => [
            ['name' => 'Status', 'picklistValues' => [
                ['value' => 'Open', 'label' => 'Open - Not Contacted'],
                ['value' => 'Closed', 'label' => 'Closed'],
            ]],
        ]]);

        $expected = ['Open' => 'Open - Not Contacted', 'Closed' => 'Closed'];

        $this->assertSame($expected, SObjects::getPicklistValues('Lead', 'Status')->all());
        $this->assertSame($expected, TestLead::getPicklistValues('Status')->all());
        $this->assertSame([], SObjects::getPicklistValues('Lead', 'Missing')->all());
    }

    public function test_describe_returns_the_description_or_one_key(): void
    {
        $this->salesforce->describes('Product2', ['name' => 'Product2', 'fields' => [['name' => 'Id']]]);

        $this->assertSame('Product2', SObjects::describe('Product2')['name']);
        $this->assertSame('Id', SObjects::describe('Product2', 'fields.0.name'));
    }

    public function test_object_builds_an_anonymous_model_from_a_record(): void
    {
        $object = SObjects::object(['attributes' => ['type' => 'Lead'], 'Id' => '00Q000000000001AAA']);

        $this->assertInstanceOf(SalesForceObject::class, $object);
        $this->assertSame('Lead', $object->getTable());
        $this->assertTrue($object->exists);
    }

    public function test_versions_are_cached_for_the_token_lifetime(): void
    {
        $calls = 0;

        $this->salesforce->resource('versions', function () use (&$calls) {
            $calls++;

            return [['version' => '62.0']];
        });

        $this->assertFalse(Cache::has('sfdc_versions'));

        SObjects::versions();
        SObjects::versions();

        $this->assertSame(1, $calls);
        $this->assertTrue(Cache::has('sfdc_versions'));

        $this->travel(config('eloquent_sf.forrest.storage.expire_in') + 1)->seconds();

        $this->assertFalse(Cache::has('sfdc_versions'));
    }

    public function test_unknown_methods_are_forwarded_to_the_rest_api(): void
    {
        $this->salesforce->resource('limits', ['DailyApiRequests' => ['Max' => 15000, 'Remaining' => 14998]]);

        $this->assertSame(14998, SObjects::limits()['DailyApiRequests']['Remaining']);
    }

    public function test_queries_are_recorded_in_the_history(): void
    {
        TestLead::select('Id')->get();

        $this->assertSame(['select Id from Lead'], SObjects::queryHistory()->all());
    }

    public function test_queries_fire_the_frameworks_query_event(): void
    {
        Event::fake([QueryExecuted::class]);

        TestLead::select('Id')->where('Company', 'Acme')->get();

        Event::assertDispatched(QueryExecuted::class, function (QueryExecuted $event) {
            return $event->sql === "select Id from Lead where Company = 'Acme'"
                && $event->bindings === []
                && $event->connectionName === 'soql';
        });
    }

    public function test_logging_can_be_disabled_or_sent_to_a_channel(): void
    {
        Log::shouldReceive('channel')->never();

        TestLead::select('Id')->get();

        config(['eloquent_sf.logging' => 'stack']);

        Log::shouldReceive('channel')->once()->with('stack')->andReturnSelf();
        Log::shouldReceive('log')->once()->with('info', 'SOQL Query', ['query' => 'select Id from Lead']);

        TestLead::select('Id')->get();
    }

    public function test_the_fake_reports_what_was_not_stubbed(): void
    {
        $this->expectException(\BadMethodCallException::class);

        SObjects::search('FIND {Acme}');
    }
}
