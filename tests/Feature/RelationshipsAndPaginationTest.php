<?php

namespace Lester\EloquentSalesForce\Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Lester\EloquentSalesForce\Model;
use Lester\EloquentSalesForce\Tests\Fixtures\TestLead;
use Lester\EloquentSalesForce\Tests\Fixtures\TestTask;
use Lester\EloquentSalesForce\Tests\TestCase;

class RelationshipsAndPaginationTest extends TestCase
{
    private const LEAD = '00Q000000000001AAA';

    public function test_has_many_filters_on_the_foreign_key(): void
    {
        $this->salesforce->records('Task', [['Id' => '00T000000000001AAA', 'WhoId' => self::LEAD, 'Subject' => 'Call']]);

        $lead = new TestLead(['Id' => self::LEAD]);

        $this->assertCount(1, $lead->tasks);
        $this->assertInstanceOf(TestTask::class, $lead->tasks->first());

        $this->salesforce->assertQueried(
            "select Id, WhoId, Subject, CreatedDate, LastModifiedDate, IsDeleted from Task where WhoId = '".self::LEAD."'"
        );
    }

    public function test_creating_through_a_relation_sets_the_foreign_key(): void
    {
        $lead = new TestLead(['Id' => self::LEAD]);

        $task = $lead->tasks()->create(['Subject' => 'Call']);

        $this->assertSame(self::LEAD, $task->WhoId);
        $this->salesforce->assertCreated('Task', ['Subject' => 'Call', 'WhoId' => self::LEAD]);
    }

    public function test_belongs_to_loads_the_parent(): void
    {
        $this->salesforce->records('Lead', [['Id' => self::LEAD, 'LastName' => 'Lovelace']]);

        $task = new TestTask(['Id' => '00T000000000001AAA', 'WhoId' => self::LEAD]);

        $this->assertSame('Lovelace', $task->lead->LastName);
        $this->salesforce->assertQueried("*from Lead where Lead.Id = '".self::LEAD."' limit 1");
    }

    public function test_has_many_can_be_eager_loaded(): void
    {
        $this->salesforce->records('Lead', [
            ['Id' => self::LEAD],
            ['Id' => '00Q000000000002AAA'],
        ]);
        $this->salesforce->records('Task', [
            ['Id' => '00T000000000001AAA', 'WhoId' => self::LEAD],
            ['Id' => '00T000000000002AAA', 'WhoId' => self::LEAD],
        ]);

        $leads = TestLead::with('tasks')->get();

        $this->assertCount(2, $leads[0]->tasks);
        $this->assertCount(0, $leads[1]->tasks);
        $this->salesforce->assertQueryCount(2);
        $this->salesforce->assertQueried("*from Task where WhoId in ('".self::LEAD."', '00Q000000000002AAA')");
    }

    public function test_updating_through_a_relation_updates_the_related_records(): void
    {
        $this->salesforce->records('Task', [['Id' => '00T000000000001AAA']]);

        $lead = new TestLead(['Id' => self::LEAD]);

        $this->assertSame(1, $lead->tasks()->update(['Status' => 'Completed']));

        $this->salesforce->assertUpdated('Task', '00T000000000001AAA', fn (array $attributes) => $attributes === ['Status' => 'Completed']);
    }

    public function test_the_default_foreign_key_follows_salesforce_naming(): void
    {
        $account = new class extends Model
        {
            protected $table = 'Account';
        };

        $this->assertStringEndsWith('Id', $account->getForeignKey());
        $this->assertSame('TestLeadId', (new TestLead)->getForeignKey());
    }

    public function test_paginate_counts_then_fetches_one_page(): void
    {
        $this->salesforce->records('Lead', array_map(fn ($i) => ['Id' => sprintf('00Q%012dAAA', $i)], range(1, 7)));

        $page = TestLead::where('Company', 'Acme')->orderBy('LastName')->paginate(3, ['Id'], 'page', 2);

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(7, $page->total());
        $this->assertSame(3, $page->lastPage());

        $this->salesforce->assertQueried("select count(Id) aggregate from Lead where Company = 'Acme'");
        $this->salesforce->assertQueried("select Id from Lead where Company = 'Acme' order by LastName asc limit 3 offset 3");
    }

    public function test_paginate_caps_the_total_at_the_soql_offset_limit(): void
    {
        $this->salesforce->whenQuery('select count(Id)*', [['aggregate' => 5000]]);

        $page = TestLead::paginate(100, ['Id']);

        $this->assertSame(2000, $page->total());
        $this->assertSame(20, $page->lastPage());
    }

    public function test_paginate_skips_the_second_query_when_nothing_matches(): void
    {
        $page = TestLead::paginate(10);

        $this->assertSame(0, $page->total());
        $this->salesforce->assertQueryCount(1);
    }
}
