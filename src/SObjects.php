<?php

namespace Lester\EloquentSalesForce;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lester\EloquentSalesForce\Clients\ForrestClient;
use Lester\EloquentSalesForce\Contracts\SalesforceClient;
use Lester\EloquentSalesForce\Database\SOQLBatch;
use Lester\EloquentSalesForce\Exceptions\RestAPIException;
use Throwable;

class SObjects
{
    /**
     * The uppercase alphabet followed by 0-5: the 32 symbols Salesforce uses
     * for the three checksum characters of an 18-character ID.
     */
    protected const ID_CHECKSUM_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    /**
     * Salesforce accepts at most this many records per sObject collection call.
     */
    public const COLLECTION_LIMIT = 200;

    /** @var SOQLBatch */
    private $batch;

    /** @var Collection */
    private $queryHistory;

    public function __construct()
    {
        $this->batch = new SOQLBatch([]);
        $this->queryHistory = collect();
    }

    /**
     * The client every Salesforce request goes through.
     *
     * Resolved on each use so a fake swapped in mid-test takes effect.
     */
    public function client(): SalesforceClient
    {
        return app(SalesforceClient::class);
    }

    /**
     * Update many records in as few API calls as possible.
     *
     * Only the dirty attributes of each model are sent. Returns one result
     * per record, as reported by Salesforce (id, success, errors).
     *
     * @param  bool  $allOrNone
     * @return Collection<int, array<string, mixed>>
     */
    public function update(Collection $collection, $allOrNone = false)
    {
        $results = new Collection;

        foreach ($collection->chunk($this->collectionChunkSize()) as $chunk) {
            $payload = [
                'allOrNone' => (bool) $allOrNone,
                'records' => $chunk->map(function ($object) {
                    return ['attributes' => $object->sf_attributes, 'Id' => $object->Id]
                        + $object->getSalesforceDirty();
                })->values()->all(),
            ];

            $this->log('SOQL Bulk Update', $payload);

            $response = $this->__call('composite', ['sobjects', [
                'method' => 'patch',
                'body' => $payload,
            ]]);

            $this->queryHistory->push(['update' => $payload['records']]);

            $this->log('SOQL Bulk Update', (array) $response);

            foreach ((array) $response as $result) {
                $results->push((array) $result);
            }
        }

        return $results;
    }

    /**
     * The configured bulk write chunk size, capped at the API maximum.
     */
    public function collectionChunkSize(): int
    {
        $size = (int) config('eloquent_sf.batch.insert.size', static::COLLECTION_LIMIT);

        return $size > 0 ? min($size, static::COLLECTION_LIMIT) : static::COLLECTION_LIMIT;
    }

    /**
     * Make sure an access token exists, requesting one if needed.
     *
     * @return mixed
     */
    public function authenticate()
    {
        return $this->client()->authenticate();
    }

    /**
     * Start the configured authentication flow.
     *
     * @return mixed
     */
    public function authorize()
    {
        return $this->client()->authorize();
    }

    /**
     * The instance URL of the authenticated org.
     *
     * @return string|null
     */
    public function instanceUrl()
    {
        return $this->client()->instanceUrl();
    }

    /**
     * Complete the WebServer flow from the OAuth callback request.
     *
     * @return mixed
     */
    public function callback()
    {
        return $this->client()->callback();
    }

    /**
     * Forward any other call to the REST API (query, sobjects, composite, ...).
     *
     * @param  string  $name
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->client()->call($name, $arguments);
    }

    /**
     * Describe an object, or return one key of its description.
     *
     * @param  string  $object
     * @param  string|null  $key
     * @param  mixed  $filter  Unused, kept for backwards compatibility.
     * @return mixed
     */
    public function describe($object, $key = null, $filter = null)
    {
        $description = $this->__call('describe', [$object]);

        return $key === null ? $description : Arr::get($description, $key);
    }

    /**
     * The fields to select for an object when no explicit columns are given.
     *
     * Uses the layout configured in eloquent_sf.layout to pick the fields.
     *
     * @param  string  $table
     * @param  array<int, mixed>  $columns
     * @return array<int, mixed>
     */
    public function objectFields($table, $columns = ['*'])
    {
        if ($columns != ['*']) {
            return $columns;
        }

        $layout = $this->__call('sobjects', [$table.'/'.config('eloquent_sf.layout').'/']);

        $columns = ['Id', 'CreatedDate', 'LastModifiedDate'];

        if (! in_array($table, config('eloquent_sf.noSoftDeletesOn', ['User']))) {
            $columns[] = 'IsDeleted';
        }

        $this->collectLayoutFields(
            Arr::pluck($layout['fieldItems'] ?? [], 'layoutComponents.0'),
            $columns
        );

        return array_values(array_unique($columns));
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  array<int, string>  $columns
     */
    private function collectLayoutFields(array $fields, array &$columns): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (Arr::get($field, 'details.updateable') == true && isset($field['details']['name'])) {
                $columns[] = $field['details']['name'];
            }

            if (isset($field['components']) && is_array($field['components'])) {
                $this->collectLayoutFields($field['components'], $columns);
            }
        }
    }

    /**
     * Instantiate an anonymous model for a Salesforce record.
     *
     * @param  array<string, mixed>  $attributes
     * @return SalesForceObject
     */
    public function object($attributes = [])
    {
        return new SalesForceObject($attributes);
    }

    /**
     * Convert a 15-character (case-sensitive) ID to its 18-character form.
     *
     * Anything that is not 15 characters long is returned unchanged.
     *
     * @param  string  $str
     * @return string
     */
    public function convert($str)
    {
        if (strlen((string) $str) !== 15) {
            return $str;
        }

        $suffix = '';

        foreach (str_split($str, 5) as $chunk) {
            $index = 0;

            // Each character of the chunk contributes one bit, least
            // significant first: 1 when it is an uppercase letter.
            foreach (str_split($chunk) as $position => $character) {
                if (ctype_upper($character)) {
                    $index |= 1 << $position;
                }
            }

            $suffix .= static::ID_CHECKSUM_ALPHABET[$index];
        }

        return $str.$suffix;
    }

    /**
     * Get the active picklist values of a field, keyed by value.
     *
     * Contributed upstream by @seankndy.
     *
     * @param  string  $object
     * @param  string  $field
     * @return Collection
     */
    public function getPicklistValues($object, $field)
    {
        $description = $this->__call('sobjects', [$object.'/describe']);

        foreach ($description['fields'] ?? [] as $candidate) {
            if (($candidate['name'] ?? null) == $field) {
                return collect($candidate['picklistValues'] ?? [])->pluck('label', 'value');
            }
        }

        return collect([]);
    }

    /**
     * The batch that queued queries are collected in.
     *
     * @return SOQLBatch
     */
    public function getBatch()
    {
        return $this->batch;
    }

    /**
     * Run the queued batch queries and start a fresh batch.
     *
     * @param  array<int, mixed>  $errors  Unused, kept for backwards compatibility.
     * @return SOQLBatch
     */
    public function runBatch(&$errors = [])
    {
        return tap($this->batch->run(), function () {
            $this->batch = new SOQLBatch([]);
        });
    }

    /**
     * Write to the log channel configured in eloquent_sf.logging.
     *
     * @param  string  $message
     * @param  mixed  $details
     * @param  string  $level
     * @return void
     */
    public function log($message, $details = [], $level = 'info')
    {
        $channel = config('eloquent_sf.logging', config('logging.default'));

        if ($channel === false || $channel === null) {
            return;
        }

        // "warn" was accepted by older releases but is not a PSR-3 level.
        $level = $level === 'warn' ? 'warning' : $level;

        Log::channel($channel)->log($level, $message, Arr::wrap($details));
    }

    /**
     * Whether a string has the shape of a Salesforce record ID.
     *
     * @param  mixed  $str
     * @return bool
     */
    public function isSalesForceId($str)
    {
        return is_string($str) && preg_match('/^[0-9a-zA-Z]{15}(?:[0-9a-zA-Z]{3})?$/', $str) === 1;
    }

    /**
     * The API versions the org supports, cached alongside the token.
     *
     * @return mixed
     */
    public function versions()
    {
        if (config('eloquent_sf.forrest.storage.store_forever')) {
            return Cache::rememberForever('sfdc_versions', fn () => $this->__call('versions', []));
        }

        $seconds = (int) config('eloquent_sf.forrest.storage.expire_in', 3600);

        return Cache::remember('sfdc_versions', max($seconds, 1), fn () => $this->__call('versions', []));
    }

    /**
     * Every statement sent to Salesforce during this request.
     *
     * @return Collection
     */
    public function queryHistory()
    {
        return $this->queryHistory;
    }

    /**
     * Throw the package exception matching the first Salesforce error.
     *
     * @param  iterable<int, object|array<string, mixed>>  $exceptions
     * @return void
     *
     * @throws RestAPIException
     */
    public function processExceptions($exceptions)
    {
        foreach ($exceptions as $error) {
            throw ForrestClient::translate(new \RuntimeException(json_encode([(array) $error])))
                ?? new RestAPIException((string) Arr::get((array) $error, 'message', 'Salesforce request failed.'));
        }
    }

    /**
     * Run a callback, converting Salesforce error payloads into exceptions.
     *
     * @return mixed
     */
    public function salesforceAttempt(Closure $callback)
    {
        try {
            return $callback();
        } catch (RestAPIException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ForrestClient::translate($e) ?? $e;
        }
    }

    /**
     * @deprecated Misspelled; use salesforceAttempt().
     *
     * @return mixed
     */
    public function saleforceAttempt(Closure $callback)
    {
        return $this->salesforceAttempt($callback);
    }
}
