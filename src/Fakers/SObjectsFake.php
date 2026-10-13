<?php

namespace Lester\EloquentSalesForce\Fakers;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lester\EloquentSalesForce\Contracts\SalesforceClient;
use Lester\EloquentSalesForce\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * An in-memory stand-in for Salesforce, installed by SObjects::fake().
 *
 * Queries answer with the records you stub; inserts, updates and deletes
 * are recorded so they can be asserted on. Nothing leaves the process.
 *
 * WHERE clauses are not evaluated: a query against an object returns all
 * records stubbed for it, unless a whenQuery() stub matches the statement.
 */
class SObjectsFake implements SalesforceClient
{
    /**
     * Stubbed records, keyed by object name.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $records = [];

    /**
     * Stubbed responses for specific statements.
     *
     * @var array<int, array{0: string|Closure, 1: mixed}>
     */
    protected array $queryStubs = [];

    /**
     * Stubbed describe results, keyed by object name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $descriptions = [];

    /**
     * Stubbed layouts, keyed by object name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $layouts = [];

    /**
     * Stubbed responses for any other REST resource, keyed by method name.
     *
     * @var array<string, mixed>
     */
    protected array $resources = [];

    /**
     * Everything that was sent, in order.
     *
     * @var array<int, array{type: string, object: string|null, id: string|null, attributes: array<string, mixed>, soql: string|null}>
     */
    protected array $recorded = [];

    protected bool $authenticated = false;

    protected int $sequence = 0;

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $records  Records to return, keyed by object name.
     */
    public function __construct(array $records = [])
    {
        foreach ($records as $object => $rows) {
            $this->records($object, $rows);
        }
    }

    /**
     * Stub the records returned by queries against an object.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return $this
     */
    public function records(string $object, array $records): static
    {
        $this->records[$object] = array_values($records);

        return $this;
    }

    /**
     * Stub the response to statements matching a pattern.
     *
     * The matcher is a wildcard pattern ("select * from Lead where Email*",
     * compared case-insensitively) or a closure receiving the statement.
     * The response is a list of records, a closure returning one, or a
     * Throwable to throw. Later stubs take precedence over earlier ones.
     *
     * @param  mixed  $response
     * @return $this
     */
    public function whenQuery(string|Closure $matcher, $response): static
    {
        $this->queryStubs[] = [$matcher, $response];

        return $this;
    }

    /**
     * Stub the describe result of an object.
     *
     * @param  array<string, mixed>  $description
     * @return $this
     */
    public function describes(string $object, array $description): static
    {
        $this->descriptions[$object] = $description;

        return $this;
    }

    /**
     * Stub the layout of an object, as a list of updateable field names.
     *
     * @param  array<int, string>  $fields
     * @return $this
     */
    public function layout(string $object, array $fields): static
    {
        $this->layouts[$object] = [
            'fieldItems' => array_map(fn ($field) => [
                'layoutComponents' => [['details' => ['name' => $field, 'updateable' => true]]],
            ], $fields),
        ];

        return $this;
    }

    /**
     * Stub the response of any other REST resource, e.g. "limits".
     *
     * @param  mixed  $response  A value, or a closure receiving the call's arguments.
     * @return $this
     */
    public function resource(string $method, $response): static
    {
        $this->resources[$method] = $response;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate()
    {
        $this->authenticated = true;

        return (object) [
            'access_token' => 'fake-token',
            'instance_url' => $this->instanceUrl(),
            'token_type' => 'Bearer',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function authorize()
    {
        return $this->authenticate();
    }

    /**
     * {@inheritDoc}
     */
    public function callback()
    {
        return $this->authenticate();
    }

    /**
     * {@inheritDoc}
     */
    public function instanceUrl(): ?string
    {
        return 'https://fake.my.salesforce.com';
    }

    /**
     * {@inheritDoc}
     */
    public function call(string $method, array $arguments = [])
    {
        $this->authenticated = true;

        if (array_key_exists($method, $this->resources)) {
            $response = $this->resources[$method];

            return $response instanceof Closure ? $response(...$arguments) : $response;
        }

        return match ($method) {
            'query', 'queryAll' => $this->query((string) ($arguments[0] ?? ''), $method === 'queryAll'),
            'next' => ['totalSize' => 0, 'done' => true, 'records' => []],
            'sobjects' => $this->sobjects((string) ($arguments[0] ?? ''), (array) ($arguments[1] ?? [])),
            'composite' => $this->composite((string) ($arguments[0] ?? ''), (array) ($arguments[1] ?? [])),
            'describe' => $this->description((string) ($arguments[0] ?? '')),
            'versions' => [['label' => 'Fake', 'url' => '/services/data/v62.0', 'version' => '62.0']],
            'resources', 'limits', 'identity' => [],
            default => throw new BadMethodCallException(
                "The Salesforce fake has no response for [{$method}]. Stub one with resource('{$method}', ...)."
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(string $soql, bool $all = false): array
    {
        $this->record($all ? 'queryAll' : 'query', $this->objectOf($soql), null, [], $soql);

        foreach (array_reverse($this->queryStubs) as [$matcher, $response]) {
            $matches = $matcher instanceof Closure
                ? (bool) $matcher($soql)
                : Str::is(Str::lower($matcher), Str::lower($soql));

            if (! $matches) {
                continue;
            }

            if ($response instanceof Closure) {
                $response = $response($soql);
            }

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $this->queryResponse((array) $response, $this->objectOf($soql));
        }

        $object = $this->objectOf($soql);
        $records = $this->records[$object] ?? [];

        if (preg_match('/^\s*select\s+count\s*\(/i', $soql) === 1) {
            return $this->queryResponse([['aggregate' => count($records)]], 'AggregateResult');
        }

        return $this->queryResponse($records, $object);
    }

    /**
     * @param  array<int|string, mixed>  $records
     * @return array<string, mixed>
     */
    protected function queryResponse(array $records, ?string $object): array
    {
        // A complete response was stubbed; pass it through untouched.
        if (array_key_exists('records', $records)) {
            return $records;
        }

        $records = array_map(function ($record) use ($object) {
            $record = (array) $record;

            return isset($record['attributes'])
                ? $record
                : ['attributes' => ['type' => $object]] + $record;
        }, array_values($records));

        return ['totalSize' => count($records), 'done' => true, 'records' => $records];
    }

    /**
     * The object a statement selects from, ignoring child sub-selects.
     */
    protected function objectOf(string $soql): ?string
    {
        $outer = $soql;

        do {
            $outer = preg_replace('/\([^()]*\)/', '', $outer, -1, $count);
        } while ($count > 0);

        return preg_match('/\bfrom\s+(\w+)/i', (string) $outer, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return mixed
     */
    protected function sobjects(string $path, array $options)
    {
        $method = strtolower((string) ($options['method'] ?? 'get'));
        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
        $object = $segments[0] ?? null;
        $id = $segments[1] ?? null;
        $body = (array) ($options['body'] ?? []);

        if ($method === 'post') {
            $id = $this->nextId();

            $this->record('create', $object, $id, $body);

            return ['id' => $id, 'success' => true, 'errors' => []];
        }

        if ($method === 'patch') {
            $this->record('update', $object, $id, $body);

            return null;
        }

        if ($method === 'delete') {
            $this->record('delete', $object, $id);

            return null;
        }

        if ($id === 'describe') {
            return isset($segments[2])
                ? ($this->layouts[$object] ?? ['fieldItems' => []])
                : $this->description((string) $object);
        }

        if ($id !== null) {
            return collect($this->records[$object] ?? [])->firstWhere('Id', $id);
        }

        return $this->description((string) $object);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return mixed
     */
    protected function composite(string $path, array $options)
    {
        $method = strtolower((string) ($options['method'] ?? 'get'));

        if (Str::startsWith($path, 'tree/')) {
            $object = Str::after($path, 'tree/');

            $results = [];

            foreach ((array) Arr::get($options, 'body.records', []) as $record) {
                $record = (array) $record;
                $id = $this->nextId();

                $this->record('create', $object, $id, Arr::except($record, ['attributes']));

                $results[] = ['referenceId' => Arr::get($record, 'attributes.referenceId'), 'id' => $id];
            }

            return ['hasErrors' => false, 'results' => $results];
        }

        if ($path === 'batch') {
            $results = [];

            foreach ((array) Arr::get($options, 'body.batchRequests', []) as $request) {
                parse_str((string) parse_url((string) ($request['url'] ?? ''), PHP_URL_QUERY), $query);

                $results[] = ['statusCode' => 200, 'result' => $this->query((string) ($query['q'] ?? ''))];
            }

            return ['hasErrors' => false, 'results' => $results];
        }

        if ($path === 'sobjects' && $method === 'patch') {
            return array_map(function ($record) {
                $record = (array) $record;

                $this->record(
                    'update',
                    Arr::get($record, 'attributes.type'),
                    $record['Id'] ?? null,
                    Arr::except($record, ['attributes', 'Id'])
                );

                return ['id' => $record['Id'] ?? null, 'success' => true, 'errors' => []];
            }, array_values((array) Arr::get($options, 'body.records', [])));
        }

        if ($path === 'sobjects' && $method === 'delete') {
            $ids = array_filter(explode(',', (string) Arr::get($options, 'query.ids', '')));

            return array_map(function ($id) {
                $this->record('delete', null, $id);

                return ['id' => $id, 'success' => true, 'errors' => []];
            }, array_values($ids));
        }

        throw new BadMethodCallException("The Salesforce fake does not support composite [{$method} {$path}].");
    }

    /**
     * @return array<string, mixed>
     */
    protected function description(string $object): array
    {
        return $this->descriptions[$object] ?? ['name' => $object, 'fields' => []];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function record(string $type, ?string $object, ?string $id, array $attributes = [], ?string $soql = null): void
    {
        $this->recorded[] = compact('type', 'object', 'id', 'attributes', 'soql');
    }

    /**
     * A syntactically valid 18-character record ID.
     */
    protected function nextId(): string
    {
        return sprintf('001%012d', ++$this->sequence).'AAA';
    }

    /**
     * Everything that was sent, optionally filtered by type: "query",
     * "queryAll", "create", "update" or "delete".
     *
     * @return Collection<int, array{type: string, object: string|null, id: string|null, attributes: array<string, mixed>, soql: string|null}>
     */
    public function recorded(?string $type = null): Collection
    {
        return collect($this->recorded)
            ->when($type, fn ($calls) => $calls->where('type', $type))
            ->values();
    }

    /**
     * The statements that were queried, in order.
     *
     * @return Collection<int, string>
     */
    public function queries(): Collection
    {
        return collect($this->recorded)->whereIn('type', ['query', 'queryAll'])->pluck('soql')->values();
    }

    /**
     * Assert a statement was queried.
     *
     * @param  string|Closure  $statement  The exact statement, a wildcard pattern, or a closure receiving each statement.
     */
    public function assertQueried(string|Closure $statement): void
    {
        PHPUnit::assertTrue(
            $this->queries()->contains(fn ($soql) => $this->statementMatches($statement, $soql)),
            'The expected statement was not queried.'.$this->queriedSummary()
        );
    }

    /**
     * Assert a statement was not queried.
     */
    public function assertNotQueried(string|Closure $statement): void
    {
        PHPUnit::assertFalse(
            $this->queries()->contains(fn ($soql) => $this->statementMatches($statement, $soql)),
            'An unexpected statement was queried.'.$this->queriedSummary()
        );
    }

    /**
     * Assert how many statements were queried.
     */
    public function assertQueryCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->queries(), 'Unexpected number of queries.'.$this->queriedSummary());
    }

    /**
     * Assert a record was inserted.
     *
     * @param  array<string, mixed>|Closure|null  $attributes  Attributes the record must have, or a closure receiving them.
     */
    public function assertCreated(string $object, array|Closure|null $attributes = null): void
    {
        PHPUnit::assertTrue(
            $this->wasRecorded('create', $object, null, $attributes),
            "The expected [{$object}] record was not created."
        );
    }

    /**
     * Assert no record of an object was inserted.
     */
    public function assertNotCreated(string $object): void
    {
        PHPUnit::assertFalse(
            $this->wasRecorded('create', $object, null, null),
            "An unexpected [{$object}] record was created."
        );
    }

    /**
     * Assert a record was updated.
     *
     * @param  array<string, mixed>|Closure|null  $attributes  Attributes the update must contain, or a closure receiving them.
     */
    public function assertUpdated(string $object, ?string $id = null, array|Closure|null $attributes = null): void
    {
        PHPUnit::assertTrue(
            $this->wasRecorded('update', $object, $id, $attributes),
            "The expected [{$object}] record was not updated."
        );
    }

    /**
     * Assert a record was deleted.
     */
    public function assertDeleted(string $id): void
    {
        PHPUnit::assertTrue(
            $this->recorded('delete')->contains('id', $id),
            "The record [{$id}] was not deleted."
        );
    }

    /**
     * Assert nothing was inserted, updated or deleted.
     */
    public function assertNothingWritten(): void
    {
        PHPUnit::assertEmpty(
            collect($this->recorded)->whereIn('type', ['create', 'update', 'delete'])->all(),
            'Records were unexpectedly written to Salesforce.'
        );
    }

    /**
     * Assert no request of any kind was made.
     */
    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->recorded, 'Requests were unexpectedly sent to Salesforce.');
    }

    /**
     * Assert the package authenticated with Salesforce.
     */
    public function assertAuthenticated(): void
    {
        PHPUnit::assertTrue($this->authenticated, 'Salesforce was never authenticated with.');
    }

    /**
     * Assert the given models were updated through SObjects::update().
     *
     * @param  Collection<int, Model>  $collection
     */
    public function assertMassUpdate(Collection $collection): void
    {
        $updated = $this->recorded('update')->pluck('id');

        PHPUnit::assertTrue(
            $collection->every(fn ($model) => $updated->contains($model->Id)),
            'The expected records were not updated.'
        );
    }

    /**
     * @param  array<string, mixed>|Closure|null  $attributes
     */
    protected function wasRecorded(string $type, ?string $object, ?string $id, array|Closure|null $attributes): bool
    {
        return $this->recorded($type)->contains(function ($call) use ($object, $id, $attributes) {
            if ($object !== null && $call['object'] !== $object) {
                return false;
            }

            if ($id !== null && $call['id'] !== $id) {
                return false;
            }

            if ($attributes instanceof Closure) {
                return (bool) $attributes($call['attributes'], $call['id']);
            }

            foreach ((array) $attributes as $key => $value) {
                if (! array_key_exists($key, $call['attributes']) || $call['attributes'][$key] != $value) {
                    return false;
                }
            }

            return true;
        });
    }

    protected function statementMatches(string|Closure $expected, string $soql): bool
    {
        if ($expected instanceof Closure) {
            return (bool) $expected($soql);
        }

        return $expected === $soql || Str::is(Str::lower($expected), Str::lower($soql));
    }

    protected function queriedSummary(): string
    {
        $queries = $this->queries();

        return $queries->isEmpty()
            ? ' No statements were queried.'
            : "\nQueried:\n - ".$queries->implode("\n - ");
    }
}
