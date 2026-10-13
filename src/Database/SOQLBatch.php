<?php

namespace Lester\EloquentSalesForce\Database;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lester\EloquentSalesForce\Database\SOQLBuilder as Builder;
use Lester\EloquentSalesForce\Facades\SObjects;

/**
 * A set of queries that are sent to Salesforce in a single composite call.
 *
 * Each item is an object holding the query's model class, its builder and,
 * once the batch has run, its results.
 */
class SOQLBatch extends Collection
{
    /**
     * Salesforce accepts at most this many sub-requests per batch call.
     */
    public const MAX_BATCH_SIZE = 25;

    /**
     * The results of a batched query.
     *
     * @param  string  $key
     * @return mixed
     */
    public function results($key)
    {
        return $this->get($key);
    }

    /**
     * The builder of a batched query.
     *
     * @param  string  $key
     * @return Builder|null
     */
    public function builder($key)
    {
        return parent::get($key, $this->emptyItem())->builder;
    }

    /**
     * The model a batched query was built for.
     *
     * @param  string  $key
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function class($key)
    {
        return parent::get($key, $this->emptyItem())->class;
    }

    /**
     * Get the results of a batched query by its tag.
     *
     * {@inheritDoc}
     */
    public function get($key, $default = null)
    {
        return parent::get($key, $this->emptyItem())->results;
    }

    /**
     * @return object{class: null, builder: null, results: Collection<int, mixed>}
     */
    private function emptyItem()
    {
        return (object) [
            'class' => null,
            'builder' => null,
            'results' => collect([]),
        ];
    }

    /**
     * Add one or more queries to the batch.
     *
     * @param  Builder  ...$builders
     * @return $this
     */
    public function query(...$builders)
    {
        foreach ($builders as $builder) {
            $this->batch($builder);
        }

        return $this;
    }

    /**
     * Add a query to the batch under a tag.
     *
     * @param  string|null  $tag
     * @return Builder
     */
    public function batch(Builder $builder, $tag = null)
    {
        $index = $this->count();

        parent::put($tag ?: class_basename($builder->getModel())."_$index", (object) [
            'class' => $builder->getModel(),
            'builder' => $builder,
            'results' => collect([]),
        ]);

        return $builder;
    }

    /**
     * Send the batched queries and attach the results to each of them.
     *
     * A query that fails gets the raw error response as its results.
     *
     * @return $this
     */
    public function run()
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $chunkSize = (int) config('eloquent_sf.batch.select.size', static::MAX_BATCH_SIZE);

        if ($chunkSize > static::MAX_BATCH_SIZE || $chunkSize < 1) {
            SObjects::log('Salesforce only allows select batches of 1 to 25 queries; using 25.', [], 'warning');

            $chunkSize = static::MAX_BATCH_SIZE;
        }

        $version = $this->apiVersion();

        foreach ($this->chunk($chunkSize) as $chunk) {
            $requests = $chunk->map(function ($query) use ($version) {
                return [
                    'method' => 'get',
                    'url' => $version.'/query?q='.urlencode($query->builder->toSql()),
                ];
            })->values()->all();

            SObjects::log('SOQL Batch Query', $requests);

            $response = SObjects::composite('batch', [
                'method' => 'post',
                'body' => ['batchRequests' => $requests],
            ]);

            $index = 0;

            foreach ($chunk as $key => $batch) {
                $result = $response['results'][$index++] ?? ['statusCode' => 0, 'result' => []];

                $batch->results = ($result['statusCode'] ?? 0) == 200
                    ? $batch->builder->hydrate($result['result']['records'] ?? [])
                    : (object) $result;

                $this->put($key, $batch);
            }
        }

        return $this;
    }

    /**
     * The API version segment for sub-request URLs, e.g. "v62.0".
     */
    protected function apiVersion(): string
    {
        $version = config('eloquent_sf.forrest.version')
            ?: (collect(SObjects::versions())->last()['version'] ?? '');

        return Str::start((string) $version, 'v');
    }
}
