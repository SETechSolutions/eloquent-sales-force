<?php

namespace SETechSolutions\EloquentSalesForce\Facades;

use Illuminate\Support\Facades\Facade;
use SETechSolutions\EloquentSalesForce\Contracts\SalesforceClient;
use SETechSolutions\EloquentSalesForce\Fakers\SObjectsFake;

/**
 * @method static mixed authenticate()
 * @method static mixed authorize()
 * @method static mixed callback()
 * @method static string|null instanceUrl()
 * @method static \Illuminate\Support\Collection update(\Illuminate\Support\Collection $collection, bool $allOrNone = false)
 * @method static int collectionChunkSize()
 * @method static mixed describe(string $object, string|null $key = null)
 * @method static array objectFields(string $table, array $columns = ['*'])
 * @method static \SETechSolutions\EloquentSalesForce\SalesForceObject object(array $attributes = [])
 * @method static string convert(string $str)
 * @method static \Illuminate\Support\Collection getPicklistValues(string $object, string $field)
 * @method static \SETechSolutions\EloquentSalesForce\Database\SOQLBatch getBatch()
 * @method static \SETechSolutions\EloquentSalesForce\Database\SOQLBatch runBatch()
 * @method static void log(string $message, mixed $details = [], string $level = 'info')
 * @method static bool isSalesForceId(mixed $str)
 * @method static mixed versions()
 * @method static \Illuminate\Support\Collection queryHistory()
 * @method static mixed query(string $soql, array $options = [])
 * @method static mixed queryAll(string $soql, array $options = [])
 * @method static mixed next(string $nextUrl, array $options = [])
 * @method static mixed sobjects(string $path, array $options = [])
 * @method static mixed composite(string $path, array $options = [])
 * @method static mixed search(string $sosl, array $options = [])
 * @method static mixed limits(array $options = [])
 * @method static mixed custom(string $uri, array $options = [])
 *
 * @see \SETechSolutions\EloquentSalesForce\SObjects
 */
class SObjects extends Facade
{
    /**
     * Replace Salesforce with an in-memory fake for the rest of the test.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $records  Records to return, keyed by object name.
     */
    public static function fake($records = []): SObjectsFake
    {
        $fake = new SObjectsFake((array) $records);

        static::getFacadeApplication()->instance(SalesforceClient::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor()
    {
        return 'sobjects';
    }
}
