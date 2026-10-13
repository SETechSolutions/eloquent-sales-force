<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SETechSolutions\EloquentSalesForce\SalesForceObject;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestLead;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestTask;
use SETechSolutions\EloquentSalesForce\Tests\Fixtures\TestUser;
use SETechSolutions\EloquentSalesForce\Tests\TestCase;

class SoqlCompilationTest extends TestCase
{
    private const LEAD_FIELDS = 'Custom_Text_Field__c, Email, FirstName, LastName, Company, Custom_Date_Field__c, Id, CreatedDate, LastModifiedDate, IsDeleted';

    public function test_it_selects_the_models_columns_by_default(): void
    {
        $this->assertSame(
            'select '.self::LEAD_FIELDS.' from Lead',
            TestLead::query()->toSql()
        );
    }

    public function test_it_adds_timestamps_and_is_deleted_to_the_default_columns(): void
    {
        $this->assertSame(
            'select Id, WhoId, Subject, CreatedDate, LastModifiedDate, IsDeleted from Task',
            TestTask::query()->toSql()
        );
    }

    public function test_it_leaves_out_timestamps_when_the_model_disables_them(): void
    {
        $task = new class extends TestTask
        {
            public $timestamps = false;
        };

        $this->assertSame('select Id, WhoId, Subject, IsDeleted from Task', $task->newQuery()->toSql());
    }

    public function test_it_leaves_out_is_deleted_for_objects_without_soft_deletes(): void
    {
        config(['eloquent_sf.noSoftDeletesOn' => ['User', 'Task']]);

        $this->assertSame(
            'select Id, WhoId, Subject, CreatedDate, LastModifiedDate from Task',
            TestTask::query()->toSql()
        );
    }

    public function test_it_reads_the_default_columns_from_the_layout_when_the_model_lists_none(): void
    {
        $this->salesforce->layout('User', ['Name', 'Email']);

        $this->assertSame(
            'select Id, CreatedDate, LastModifiedDate, Name, Email from User',
            TestUser::query()->toSql()
        );
    }

    public function test_it_keeps_an_explicit_selection(): void
    {
        $this->assertSame(
            "select Id, Email from Lead where Email = 'a@b.co'",
            TestLead::select('Id', 'Email')->where('Email', 'a@b.co')->toSql()
        );
    }

    public function test_it_expands_an_explicit_star(): void
    {
        $this->assertSame(
            'select '.self::LEAD_FIELDS.' from Lead',
            TestLead::select('*')->toSql()
        );
    }

    public function test_it_quotes_strings_and_leaves_numbers_and_booleans_bare(): void
    {
        $sql = TestLead::select('Id')
            ->where('Company', 'Acme')
            ->where('NumberOfEmployees', '>', 50)
            ->where('AnnualRevenue', '<=', 1250.5)
            ->where('DoNotCall', false)
            ->where('HasOptedOutOfEmail', '!=', true)
            ->toSql();

        $this->assertSame(
            "select Id from Lead where Company = 'Acme' and NumberOfEmployees > 50 and AnnualRevenue <= 1250.5 and DoNotCall = FALSE and HasOptedOutOfEmail != TRUE",
            $sql
        );
    }

    public function test_numeric_strings_stay_strings(): void
    {
        $this->assertSame(
            "select Id from Lead where Custom_Text_Field__c = '009'",
            TestLead::select('Id')->where('Custom_Text_Field__c', '009')->toSql()
        );
    }

    #[DataProvider('injectionAttempts')]
    public function test_string_values_cannot_break_out_of_their_literal(string $value, string $expected): void
    {
        $this->assertSame(
            "select Id from Lead where LastName = '{$expected}'",
            TestLead::select('Id')->where('LastName', $value)->toSql()
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function injectionAttempts(): array
    {
        return [
            'single quote' => ["O'Neil", "O\\'Neil"],
            'closing the literal' => ["x' OR Name != '", "x\\' OR Name != \\'"],
            'trailing backslash' => ['x\\', 'x\\\\'],
            'backslash then quote' => ["x\\' OR Id != '", "x\\\\\\' OR Id != \\'"],
            'newline' => ["a\nb", 'a\\nb'],
            'question mark' => ['what?', 'what?'],
        ];
    }

    public function test_the_executed_statement_is_escaped_too(): void
    {
        TestLead::select('Id')->where('LastName', "x' OR Name != '")->get();

        $this->salesforce->assertQueried("select Id from Lead where LastName = 'x\\' OR Name != \\''");
    }

    public function test_values_containing_question_marks_do_not_shift_later_bindings(): void
    {
        $this->assertSame(
            "select Id from Lead where FirstName = 'who?' and LastName = 'me'",
            TestLead::select('Id')->where('FirstName', 'who?')->where('LastName', 'me')->toSql()
        );
    }

    public function test_where_in(): void
    {
        $this->assertSame(
            "select Id from Lead where FirstName in ('Kathy', 'Betty')",
            TestLead::select('Id')->whereIn('FirstName', ['Kathy', 'Betty'])->toSql()
        );

        $this->assertSame(
            "select Id from Lead where FirstName not in ('Kathy')",
            TestLead::select('Id')->whereNotIn('FirstName', ['Kathy'])->toSql()
        );
    }

    public function test_empty_in_lists_compile_to_valid_soql(): void
    {
        $this->assertSame(
            'select Id from Lead where Id = null',
            TestLead::select('Id')->whereIn('FirstName', [])->toSql()
        );

        $this->assertSame(
            'select Id from Lead where Id != null',
            TestLead::select('Id')->whereNotIn('FirstName', [])->toSql()
        );
    }

    public function test_null_checks(): void
    {
        $this->assertSame(
            'select Id from Lead where Email = NULL and Phone <> NULL',
            TestLead::select('Id')->whereNull('Email')->whereNotNull('Phone')->toSql()
        );
    }

    public function test_not_like(): void
    {
        $this->assertSame(
            "select Id from Lead where (not FirstName like 'x%') or Owner.UserRole.Name like 'y%'",
            TestLead::select('Id')
                ->where('FirstName', 'not like', 'x%')
                ->orWhere('Owner.UserRole.Name', 'like', 'y%')
                ->toSql()
        );
    }

    public function test_nested_conditions(): void
    {
        $sql = TestLead::select('Id')
            ->where('Company', 'Acme')
            ->where(fn ($query) => $query->where('FirstName', 'A')->orWhere('FirstName', 'B'))
            ->toSql();

        $this->assertSame(
            "select Id from Lead where Company = 'Acme' and (FirstName = 'A' or FirstName = 'B')",
            $sql
        );
    }

    public function test_between_is_compiled_as_two_comparisons(): void
    {
        $this->assertSame(
            'select Id from Lead where (NumberOfEmployees >= 10 and NumberOfEmployees <= 20)',
            TestLead::select('Id')->whereBetween('NumberOfEmployees', [10, 20])->toSql()
        );

        $this->assertSame(
            'select Id from Lead where (NumberOfEmployees < 10 or NumberOfEmployees > 20)',
            TestLead::select('Id')->whereNotBetween('NumberOfEmployees', [10, 20])->toSql()
        );
    }

    public function test_order_limit_and_offset(): void
    {
        $this->assertSame(
            'select Id from Lead order by LastName asc, CreatedDate desc limit 10 offset 20',
            TestLead::select('Id')->orderBy('LastName')->orderByDesc('CreatedDate')->limit(10)->offset(20)->toSql()
        );
    }

    public function test_raw_conditions_with_bindings(): void
    {
        $this->assertSame(
            "select Id from Lead where Company = 'Acme' and Name like 'A%' and City = 'Paris'",
            TestLead::select('Id')
                ->where('Company', 'Acme')
                ->whereRaw('Name like ?', ['A%'])
                ->where('City', 'Paris')
                ->toSql()
        );
    }

    public function test_raw_expressions_are_inlined(): void
    {
        $this->assertSame(
            'select Id from Lead where CreatedDate > LAST_N_DAYS:3 and Company = \'Acme\'',
            TestLead::select('Id')
                ->where('CreatedDate', '>', DB::raw('LAST_N_DAYS:3'))
                ->where('Company', 'Acme')
                ->toSql()
        );
    }

    public function test_date_time_fields_are_compared_in_utc_without_quotes(): void
    {
        $date = Carbon::parse('2024-03-10 12:30:00', 'Europe/Paris');

        $this->assertSame(
            'select Id from Lead where CreatedDate >= 2024-03-10T11:30:00Z',
            TestLead::select('Id')->where('CreatedDate', '>=', $date)->toSql()
        );
    }

    public function test_date_only_fields_are_compared_as_dates(): void
    {
        $date = Carbon::parse('2024-03-10 23:30:00');

        $this->assertSame(
            'select Id from Lead where Custom_Date_Field__c >= 2024-03-10',
            TestLead::select('Id')->where('Custom_Date_Field__c', '>=', $date)->toSql()
        );
    }

    public function test_date_fields_declared_through_casts_are_recognised(): void
    {
        $lead = new class extends TestLead
        {
            protected $casts = ['Birthday__c' => 'date', 'Seen__c' => 'datetime'];
        };

        $date = Carbon::parse('2024-03-10 08:00:00', 'UTC');

        $this->assertSame(
            'select Id from Lead where Birthday__c = 2024-03-10 and Seen__c > 2024-03-10T08:00:00Z',
            $lead->newQuery()->select('Id')->where('Birthday__c', $date)->where('Seen__c', '>', $date)->toSql()
        );
    }

    public function test_date_times_work_on_undeclared_fields(): void
    {
        $this->assertSame(
            'select Id from Lead where Other_Date__c < 2024-03-10T08:00:00Z',
            TestLead::select('Id')->where('Other_Date__c', '<', Carbon::parse('2024-03-10 08:00:00', 'UTC'))->toSql()
        );
    }

    public function test_date_strings_are_accepted_on_date_fields(): void
    {
        $this->assertSame(
            'select Id from Lead where CreatedDate > 2024-01-01T00:00:00Z and Custom_Date_Field__c = 2024-01-02',
            TestLead::select('Id')
                ->where('CreatedDate', '>', '2024-01-01T00:00:00Z')
                ->where('Custom_Date_Field__c', '2024-01-02')
                ->toSql()
        );
    }

    public function test_where_date(): void
    {
        $this->assertSame(
            'select Id from Lead where Custom_Date_Field__c >= 2024-03-10',
            TestLead::select('Id')->whereDate('Custom_Date_Field__c', '>=', Carbon::parse('2024-03-10 15:00'))->toSql()
        );
    }

    public function test_calendar_functions(): void
    {
        $this->assertSame(
            'select Id from Lead where CALENDAR_YEAR(CreatedDate) = 2024 and CALENDAR_MONTH(CreatedDate) = 3 and DAY_IN_MONTH(CreatedDate) > 9',
            TestLead::select('Id')
                ->whereYear('CreatedDate', 2024)
                ->whereMonth('CreatedDate', 3)
                ->whereDay('CreatedDate', '>', 9)
                ->toSql()
        );
    }

    #[DataProvider('dateLiterals')]
    public function test_date_literals_are_written_bare(string $literal): void
    {
        $this->assertSame(
            "select Id from Lead where CreatedDate = {$literal}",
            TestLead::select('Id')->where('CreatedDate', $literal)->toSql()
        );

        $this->assertSame(
            "select Id from Lead where Undeclared_Date__c > {$literal}",
            TestLead::select('Id')->where('Undeclared_Date__c', '>', $literal)->toSql()
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dateLiterals(): array
    {
        return [
            'THIS_WEEK' => ['THIS_WEEK'],
            'TODAY' => ['TODAY'],
            'LAST_N_DAYS:7' => ['LAST_N_DAYS:7'],
            'NEXT_N_FISCAL_QUARTERS:2' => ['NEXT_N_FISCAL_QUARTERS:2'],
            'LAST_N_FISCAL_YEARS:1' => ['LAST_N_FISCAL_YEARS:1'],
            'N_DAYS_AGO:30' => ['N_DAYS_AGO:30'],
        ];
    }

    #[DataProvider('invalidDateOperands')]
    public function test_date_fields_reject_values_that_are_not_dates(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestLead::select('Id')->where('CreatedDate', '>', $value)->toSql();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDateOperands(): array
    {
        return [
            'injection through a literal' => ["LAST_N_DAYS:1 OR Name != ''"],
            'injection after a date' => ["2024-01-01 OR Name != ''"],
            'unknown literal' => ['NOT_A_LITERAL'],
            'literal with a non-numeric count' => ['LAST_N_DAYS:x'],
            'free text' => ['yesterday afternoon'],
        ];
    }

    public function test_a_date_literal_lookalike_on_a_text_field_is_not_an_injection_vector(): void
    {
        $this->assertSame(
            "select Id from Lead where LastName = 'LAST_N_DAYS:1 OR Name != \\'\\''",
            TestLead::select('Id')->where('LastName', "LAST_N_DAYS:1 OR Name != ''")->toSql()
        );
    }

    public function test_count_uses_the_record_id(): void
    {
        $this->salesforce->records('Lead', [['Id' => '00Q000000000001AAA'], ['Id' => '00Q000000000002AAA']]);

        $this->assertSame(2, TestLead::where('Company', 'Acme')->count());

        $this->salesforce->assertQueried("select count(Id) aggregate from Lead where Company = 'Acme'");
    }

    public function test_joins_become_child_sub_selects(): void
    {
        $this->salesforce->layout('Task', ['Subject']);

        $this->assertSame(
            "select Id , (select Id,CreatedDate,LastModifiedDate,IsDeleted,Subject from Tasks) from Lead where Email = 'a@b.co'",
            TestLead::select('Id')->join('Task', 'WhoId')->where('Email', 'a@b.co')->toSql()
        );
    }

    public function test_joins_use_the_relationship_name_salesforce_reports(): void
    {
        $this->salesforce->describes('Account__c', ['childRelationships' => [
            ['childSObject' => 'Contact__c', 'field' => 'Owner__c', 'relationshipName' => 'Owned__r'],
            ['childSObject' => 'Contact__c', 'field' => 'Account__c', 'relationshipName' => 'Contacts__r'],
        ]]);
        $this->salesforce->layout('Contact__c', ['Email__c']);

        $account = new class extends TestLead
        {
            protected $table = 'Account__c';
        };

        $expected = 'select Id , (select Id,CreatedDate,LastModifiedDate,IsDeleted,Email__c from Contacts__r) from Account__c';

        $this->assertSame($expected, $account->newQuery()->select('Id')->join('Contact__c', 'Account__c')->toSql(), 'by child object and field');
        $this->assertSame($expected, $account->newQuery()->select('Id')->join('Contacts__r', 'Account__c')->toSql(), 'by relationship name');
        $this->assertSame($expected, $account->newQuery()->select('Id')->join('Contact__c as Contacts__r', 'Account__c')->toSql(), 'both given');
    }

    public function test_lock_for_update(): void
    {
        $this->assertSame(
            'select Id from Lead FOR UPDATE',
            TestLead::select('Id')->lockForUpdate()->toSql()
        );
    }

    public function test_only_trashed(): void
    {
        $this->assertSame(
            'select Id from Lead where IsDeleted = TRUE',
            TestLead::select('Id')->onlyTrashed()->toSql()
        );
    }

    public function test_anonymous_objects_can_query_any_object(): void
    {
        $this->assertSame(
            "select Id from Opportunity where StageName = 'Closed Won'",
            SalesForceObject::select('Id')->from('Opportunity')->where('StageName', 'Closed Won')->toSql()
        );
    }

    public function test_find_filters_on_the_record_id(): void
    {
        TestLead::select('Id')->find('00Q000000000001AAA');

        $this->salesforce->assertQueried("select Id from Lead where Lead.Id = '00Q000000000001AAA' limit 1");
    }
}
