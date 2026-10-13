<?php

namespace SETechSolutions\EloquentSalesForce\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use SETechSolutions\EloquentSalesForce\Facades\SObjects;
use SETechSolutions\EloquentSalesForce\Model;

/**
 * @property Model $model
 */
class SOQLBuilder extends Builder
{
    /**
     * SOQL refuses an OFFSET larger than this.
     */
    public const MAX_OFFSET = 2000;

    /**
     * {@inheritDoc}
     */
    public function __construct(QueryBuilder $query)
    {
        // Models hand over a SOQL query already; this covers base queries
        // that were built against another connection.
        if (! $query->connection instanceof SOQLConnection) {
            $connection = new SOQLConnection;

            $query->connection = $connection;
            $query->grammar = $connection->getQueryGrammar();
            $query->processor = $connection->getPostProcessor();
        }

        parent::__construct($query);
    }

    /**
     * Set a model instance for the model being queried.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return $this
     */
    public function setModel($model)
    {
        parent::setModel($model);

        if ($this->query->grammar instanceof SOQLGrammar) {
            $this->query->grammar->setModel($model);
        }

        return $this;
    }

    /**
     * Queue this query in the shared batch instead of running it now.
     *
     * @param  string|null  $tag
     * @return SOQLBuilder
     */
    public function batch($tag = null)
    {
        return SObjects::getBatch()->batch($this, $tag);
    }

    /**
     * Get the SOQL statement for the query, with its values inlined.
     *
     * @return string
     */
    public function toSql()
    {
        $query = clone $this->applyScopes()->getQuery();

        if (is_null($query->aggregate) && (is_null($query->columns) || in_array('*', $query->columns, true))) {
            $query->columns = $this->expandColumns($query->columns ?: ['*']);
        }

        return $this->soqlConnection($query)->prepare($query->toSql(), $query->getBindings());
    }

    /**
     * {@inheritDoc}
     */
    public function getModels($columns = ['*'])
    {
        $this->expandSelectedColumns();

        return parent::getModels($this->expandColumns($columns));
    }

    /**
     * {@inheritDoc}
     */
    public function cursor()
    {
        if (is_null($this->query->columns)) {
            $this->query->columns = $this->expandColumns(['*']);
        } else {
            $this->expandSelectedColumns();
        }

        return parent::cursor();
    }

    /**
     * Determine if any record exists for the query.
     *
     * @return bool
     */
    public function exists()
    {
        $query = $this->applyScopes()->getQuery()
            ->cloneWithout(['columns', 'orders'])
            ->cloneWithoutBindings(['select', 'order']);

        return count($query->select('Id')->limit(1)->get()) > 0;
    }

    /**
     * Determine if no record exists for the query.
     *
     * @return bool
     */
    public function doesntExist()
    {
        return ! $this->exists();
    }

    /**
     * {@inheritDoc}
     *
     * SOQL cannot skip more than 2,000 records, so the reported total is
     * capped there: pages beyond it could not be fetched anyway.
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = value($total) ?? $this->countForPagination();

        $total = min((int) $total, static::MAX_OFFSET);

        $perPage = value($perPage, $total) ?: $this->model->getPerPage();

        $results = $total
            ? $this->forPage($page, $perPage)->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Count the records matching the query, ignoring ordering and paging.
     */
    protected function countForPagination(): int
    {
        $query = $this->applyScopes()->getQuery()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);

        $query->aggregate = ['function' => 'count', 'columns' => ['Id']];

        $row = (array) ($query->get()[0] ?? []);

        return (int) ($row['aggregate'] ?? $row['expr0'] ?? 0);
    }

    /**
     * Insert many records with as few API calls as possible.
     *
     * Accepts model instances or plain attribute arrays and returns the
     * inserted models with their new IDs.
     *
     * @param  iterable<int, Model|array<string, mixed>>  $collection
     * @return Collection<int, Model>
     */
    public function insert($collection)
    {
        $table = $this->model->getTable();

        // A single record given as an attribute array, as Eloquent allows.
        if (is_array($collection) && Arr::isAssoc($collection)) {
            $collection = [$collection];
        }

        $models = Collection::make($collection)->values()->map(function ($record) {
            return $record instanceof Model ? $record : $this->model->newInstance((array) $record);
        });

        $inserted = new Collection;

        foreach ($models->chunk(SObjects::collectionChunkSize()) as $chunk) {
            $chunk = $chunk->values();

            $payload = [
                'method' => 'post',
                'body' => [
                    'records' => $chunk->map(function (Model $model, $index) use ($table) {
                        return ['attributes' => ['type' => $table, 'referenceId' => 'ref'.$index]]
                            + $model->toSalesforceValues($model->getAttributes());
                    })->all(),
                ],
            ];

            SObjects::log('SOQL Bulk Insert', $payload);

            $response = SObjects::composite('tree/'.$table, $payload);

            SObjects::queryHistory()->push(['insert' => $payload['body']['records']]);

            foreach ($response['results'] ?? [] as $result) {
                $index = (int) substr((string) ($result['referenceId'] ?? ''), 3);

                if (! isset($result['id'], $chunk[$index])) {
                    continue;
                }

                $inserted->push($chunk[$index]->markAsInserted($result['id']));
            }
        }

        return $inserted;
    }

    /**
     * Update every record matching the query.
     *
     * Salesforce has no "update where", so the matching IDs are fetched and
     * updated through the sObject collections API. Model events do not fire.
     *
     * @param  array<string, mixed>  $values
     * @param  bool  $allOrNone
     * @return int The number of records Salesforce reports as updated.
     */
    public function update(array $values, $allOrNone = false)
    {
        $models = $this->matchingRecords()->each->forceFill($values);

        if ($models->isEmpty()) {
            return 0;
        }

        return SObjects::update($models, $allOrNone)
            ->filter(fn ($result) => ! empty($result['success']))
            ->count();
    }

    /**
     * Delete every record matching the query.
     *
     * @param  bool  $allOrNone
     * @return int The number of records sent for deletion.
     */
    public function delete($allOrNone = false)
    {
        $ids = $this->matchingRecords()->pluck('Id')->filter()->values();

        foreach ($ids->chunk(\SETechSolutions\EloquentSalesForce\SObjects::COLLECTION_LIMIT) as $chunk) {
            SObjects::composite('sobjects', [
                'method' => 'delete',
                'query' => [
                    'allOrNone' => $allOrNone ? 'true' : 'false',
                    'ids' => $chunk->implode(','),
                ],
            ]);

            SObjects::queryHistory()->push(['delete' => $chunk->values()]);
        }

        return $ids->count();
    }

    /**
     * Delete every record of the object.
     *
     * @return int
     */
    public function truncate()
    {
        return $this->delete();
    }

    /**
     * The records matching the query, loaded with their ID only.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    protected function matchingRecords()
    {
        $builder = clone $this;

        $builder->setQuery(
            $this->applyScopes()->getQuery()
                ->cloneWithout(['columns'])
                ->cloneWithoutBindings(['select'])
                ->select('Id')
        );

        return $builder->setEagerLoads([])->get();
    }

    /**
     * Include deleted and archived records in the results.
     *
     * @return $this
     */
    public function withTrashed()
    {
        $connection = new SOQLConnection(true);

        $connection->setGrammar($this->query->grammar);

        $this->query->connection = $connection;

        return $this;
    }

    /**
     * Only return deleted records.
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        return $this->withTrashed()->where('IsDeleted', true);
    }

    /**
     * Get the active picklist values of a field of the queried object.
     *
     * @param  string  $field
     * @return Collection
     */
    public function getPicklistValues($field)
    {
        return SObjects::getPicklistValues($this->model->getTable(), $field);
    }

    /**
     * Query a different object with this builder.
     *
     * @param  string  $table
     * @return $this
     */
    public function from($table)
    {
        $this->model->setTable($table);
        $this->query->from($table);

        return $this;
    }

    /**
     * SOQL compares times like any other value.
     *
     * @param  mixed  ...$args
     * @return $this
     */
    public function whereTime(...$args)
    {
        return $this->where(...$args);
    }

    /**
     * The fields selected when the query does not name any.
     *
     * @return array<int, mixed>
     */
    public function describe()
    {
        $columns = $this->model->columns;

        if (! count($columns)) {
            return $this->getSalesForceColumns(['*']);
        }

        if ($this->model->usesTimestamps()) {
            $columns[] = $this->model->getCreatedAtColumn();
            $columns[] = $this->model->getUpdatedAtColumn();
        }

        if ($this->model->usesSalesforceSoftDeletes()) {
            $columns[] = 'IsDeleted';
        }

        return array_values(array_unique(array_filter($columns)));
    }

    /**
     * Replace a "*" selection with the model's default fields.
     *
     * @param  array<int, mixed>|mixed  $columns
     * @return array<int, mixed>
     */
    protected function expandColumns($columns)
    {
        $columns = (array) $columns;

        if (! in_array('*', $columns, true)) {
            return $columns;
        }

        $expanded = [];

        foreach ($columns as $column) {
            $expanded = array_merge($expanded, $column === '*' ? $this->describe() : [$column]);
        }

        return array_values(array_unique($expanded, SORT_REGULAR));
    }

    /**
     * Expand a "*" that was selected explicitly, e.g. through select('*').
     */
    protected function expandSelectedColumns(): void
    {
        if (is_array($this->query->columns) && in_array('*', $this->query->columns, true)) {
            $this->query->columns = $this->expandColumns($this->query->columns);
        }
    }

    /**
     * Resolve the columns to select for an object.
     *
     * @param  array<int, mixed>  $columns
     * @param  string|null  $table
     * @return array<int, mixed>
     */
    protected function getSalesForceColumns($columns, $table = null)
    {
        return SObjects::objectFields($table ?: $this->model->getTable(), $columns);
    }

    /**
     * The SOQL connection behind a base query.
     */
    protected function soqlConnection(QueryBuilder $query): SOQLConnection
    {
        $connection = $query->getConnection();

        return $connection instanceof SOQLConnection ? $connection : new SOQLConnection;
    }
}
