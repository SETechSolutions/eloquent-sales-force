<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Feature;

use Lester\EloquentSalesForce\Facades\SObjects;
use Lester\EloquentSalesForce\Observers\Syncronizer;
use Lester\EloquentSalesForce\SalesForceObject;
use PHPUnit\Framework\Attributes\DataProvider;
use SETechSolutions\EloquentSalesForce\Database\SOQLBuilder;
use SETechSolutions\EloquentSalesForce\Exceptions\MalformedQueryException;
use SETechSolutions\EloquentSalesForce\Model;
use SETechSolutions\EloquentSalesForce\Observers\Synchronizer;
use SETechSolutions\EloquentSalesForce\Tests\TestCase;

/**
 * Applications written for rob-lester-jr04/eloquent-sales-force keep using
 * the Lester\EloquentSalesForce class names.
 */
class LegacyNamespaceTest extends TestCase
{
    public function test_a_model_extending_the_old_base_class_works(): void
    {
        $this->salesforce->records('Contact', [['Id' => '003000000000001AAA', 'LastName' => 'Lovelace']]);

        $contact = new class extends \Lester\EloquentSalesForce\Model
        {
            protected $table = 'Contact';

            public $columns = ['Id', 'LastName'];
        };

        $this->assertInstanceOf(Model::class, $contact);

        $found = $contact->newQuery()->where('LastName', 'Lovelace')->first();

        $this->assertSame('003000000000001AAA', $found->Id);
        $this->assertInstanceOf(\Lester\EloquentSalesForce\Model::class, $found);
    }

    public function test_the_old_facade_and_anonymous_object_work(): void
    {
        $this->assertSame('5003000000D8cuIAAR', SObjects::convert('5003000000D8cuI'));

        $this->assertSame(
            'select Id from Lead',
            SalesForceObject::select('Id')->from('Lead')->toSql()
        );
    }

    public function test_old_type_declarations_accept_new_objects(): void
    {
        // Using any old name registers all of them, so a declaration naming
        // a class that was never loaded by its old name still matches.
        class_exists(\Lester\EloquentSalesForce\Model::class);

        $accepts = fn (\Lester\EloquentSalesForce\Database\SOQLBuilder $builder) => $builder;

        $builder = \SETechSolutions\EloquentSalesForce\SalesForceObject::query();

        $this->assertInstanceOf(SOQLBuilder::class, $accepts($builder));
    }

    public function test_exceptions_can_be_caught_by_their_old_name(): void
    {
        $this->salesforce->whenQuery('*', new MalformedQueryException('bad'));

        $this->expectException(\Lester\EloquentSalesForce\Exceptions\MalformedQueryException::class);

        \SETechSolutions\EloquentSalesForce\SalesForceObject::select('Id')->from('Lead')->get();
    }

    public function test_the_misspelled_observer_name_still_resolves(): void
    {
        $this->assertSame(Synchronizer::class, (new \ReflectionClass(Syncronizer::class))->getName());
    }

    #[DataProvider('legacyClasses')]
    public function test_every_class_of_the_2_x_releases_has_an_alias(string $legacy): void
    {
        $this->assertTrue(
            class_exists($legacy) || trait_exists($legacy),
            "{$legacy} is not aliased."
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function legacyClasses(): array
    {
        $classes = [
            'Console\\MakeModelCommand', 'Console\\SyncFromSalesforce',
            'Database\\SOQLBatch', 'Database\\SOQLBuilder', 'Database\\SOQLConnection', 'Database\\SOQLGrammar',
            'Database\\SOQLHasMany', 'Database\\SOQLHasOne', 'Database\\SOQLHasOneOrMany',
            'Exceptions\\MalformedQueryException', 'Exceptions\\RequestLimitExceeded',
            'Exceptions\\RestAPIException', 'Exceptions\\UnableToLockRowException',
            'Facades\\SObjects', 'Fakers\\SObjectsFake', 'Model', 'Observers\\Syncronizer',
            'SObjects', 'SalesForceObject', 'ServiceProvider', 'Traits\\SyncsWithSalesforce',
        ];

        return collect($classes)
            ->mapWithKeys(fn ($class) => [$class => ['Lester\\EloquentSalesForce\\'.$class]])
            ->all();
    }
}
