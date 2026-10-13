<?php

namespace Lester\EloquentSalesForce\Database;

use DateTimeInterface;
use DateTimeZone;
use Generator;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;
use Lester\EloquentSalesForce\Facades\SObjects;

/**
 * A database connection that speaks SOQL over the Salesforce REST API.
 *
 * There is no PDO behind it: statements are compiled by SOQLGrammar, the
 * bindings are serialised into SOQL literals here, and the finished
 * statement is sent through the SObjects facade.
 */
class SOQLConnection extends Connection
{
    /**
     * Placeholder for a value compared against a dateTime field.
     */
    public const DATETIME_PLACEHOLDER = '{?datetime}';

    /**
     * Placeholder for a value compared against a date-only field.
     */
    public const DATE_PLACEHOLDER = '{?date}';

    /**
     * Placeholder for a value that must be sent as a bare integer.
     */
    public const INTEGER_PLACEHOLDER = '{?int}';

    /**
     * SOQL date literals that take no argument.
     *
     * @see https://developer.salesforce.com/docs/atlas.en-us.soql_sosl.meta/soql_sosl/sforce_api_calls_soql_select_dateformats.htm
     */
    protected const DATE_LITERALS = [
        'YESTERDAY', 'TODAY', 'TOMORROW',
        'LAST_WEEK', 'THIS_WEEK', 'NEXT_WEEK',
        'LAST_MONTH', 'THIS_MONTH', 'NEXT_MONTH',
        'LAST_90_DAYS', 'NEXT_90_DAYS',
        'THIS_QUARTER', 'LAST_QUARTER', 'NEXT_QUARTER',
        'THIS_YEAR', 'LAST_YEAR', 'NEXT_YEAR',
        'THIS_FISCAL_QUARTER', 'LAST_FISCAL_QUARTER', 'NEXT_FISCAL_QUARTER',
        'THIS_FISCAL_YEAR', 'LAST_FISCAL_YEAR', 'NEXT_FISCAL_YEAR',
    ];

    /**
     * SOQL date literals that take the form NAME:n.
     */
    protected const DATE_LITERALS_WITH_COUNT = [
        'LAST_N_DAYS', 'NEXT_N_DAYS', 'N_DAYS_AGO',
        'LAST_N_WEEKS', 'NEXT_N_WEEKS', 'N_WEEKS_AGO',
        'LAST_N_MONTHS', 'NEXT_N_MONTHS', 'N_MONTHS_AGO',
        'LAST_N_QUARTERS', 'NEXT_N_QUARTERS', 'N_QUARTERS_AGO',
        'LAST_N_YEARS', 'NEXT_N_YEARS', 'N_YEARS_AGO',
        'LAST_N_FISCAL_QUARTERS', 'NEXT_N_FISCAL_QUARTERS', 'N_FISCAL_QUARTERS_AGO',
        'LAST_N_FISCAL_YEARS', 'NEXT_N_FISCAL_YEARS', 'N_FISCAL_YEARS_AGO',
    ];

    /**
     * Whether queries should include deleted and archived records.
     *
     * @var bool
     */
    private $all = false;

    /**
     * @param  bool  $all  Query deleted and archived records too (queryAll).
     */
    public function __construct($all = false)
    {
        $this->all = (bool) $all;

        // There is no PDO behind this connection.
        /** @phpstan-ignore argument.type */
        parent::__construct(null, '', '', ['name' => 'soql', 'driver' => 'soql']);

        if (function_exists('app') && app()->bound('events')) {
            $this->setEventDispatcher(app('events'));
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultQueryGrammar()
    {
        return new SOQLGrammar($this);
    }

    /**
     * Set the query grammar used by the connection.
     *
     * @param  Grammar  $grammar
     * @return $this
     */
    public function setGrammar($grammar)
    {
        $this->queryGrammar = $grammar;

        return $this;
    }

    /**
     * Whether this connection also returns deleted and archived records.
     */
    public function includesTrashed(): bool
    {
        return $this->all;
    }

    /**
     * {@inheritDoc}
     */
    public function getDriverTitle()
    {
        return 'Salesforce';
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if ($this->pretending()) {
            return [];
        }

        $statement = $this->prepare($query, $bindings);

        $start = microtime(true);

        $result = $this->send($statement);

        $records = $result['records'] ?? [];

        while (isset($result['nextRecordsUrl'])) {
            $result = SObjects::next($result['nextRecordsUrl']);

            if (isset($result['records'])) {
                $records = array_merge($records, $result['records']);
            }
        }

        $this->recordStatement($statement, $start);

        return $records;
    }

    /**
     * {@inheritDoc}
     *
     * @return Generator<int, mixed>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if ($this->pretending()) {
            return;
        }

        $statement = $this->prepare($query, $bindings);

        $start = microtime(true);

        $result = $this->send($statement);

        $this->recordStatement($statement, $start);

        while (true) {
            foreach ($result['records'] ?? [] as $record) {
                yield $record;
            }

            if (! isset($result['nextRecordsUrl'])) {
                break;
            }

            $result = SObjects::next($result['nextRecordsUrl']);
        }
    }

    /**
     * Send a finished SOQL statement to Salesforce.
     *
     * @return array<string, mixed>
     */
    protected function send(string $statement)
    {
        return (array) ($this->all ? SObjects::queryAll($statement) : SObjects::query($statement));
    }

    /**
     * Record a statement in the query history, the package log and the
     * framework's query events (QueryExecuted, DB::listen, query log).
     */
    protected function recordStatement(string $statement, float $start): void
    {
        SObjects::queryHistory()->push($statement);

        SObjects::log('SOQL Query', ['query' => $statement]);

        // The statement already has its bindings inlined, so none are passed
        // on: listeners that quote bindings through PDO have nothing to do.
        $this->logQuery($statement, [], $this->getElapsedTime($start));
    }

    /**
     * Inline the bindings into a compiled statement, producing the SOQL that
     * is sent to Salesforce.
     *
     * @param  string  $query
     * @param  array<int, mixed>  $bindings
     * @return string
     */
    public function prepare($query, $bindings)
    {
        $bindings = array_values($bindings);
        $index = 0;

        $pattern = '/'.implode('|', array_map(fn ($placeholder) => preg_quote($placeholder, '/'), [
            static::DATETIME_PLACEHOLDER,
            static::DATE_PLACEHOLDER,
            static::INTEGER_PLACEHOLDER,
        ])).'|\?/';

        return (string) preg_replace_callback($pattern, function ($match) use ($bindings, &$index) {
            if (! array_key_exists($index, $bindings)) {
                return $match[0];
            }

            $value = $bindings[$index++];

            return match ($match[0]) {
                static::DATETIME_PLACEHOLDER => $this->dateOperand($value, false),
                static::DATE_PLACEHOLDER => $this->dateOperand($value, true),
                static::INTEGER_PLACEHOLDER => $this->integerOperand($value),
                default => $this->literal($value),
            };
        }, $query);
    }

    /**
     * Serialise a PHP value as a SOQL literal.
     *
     * @param  mixed  $value
     */
    public function literal($value): string
    {
        if ($value instanceof Expression) {
            return (string) $value->getValue($this->getQueryGrammar());
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => $this->floatLiteral($value),
            $value instanceof DateTimeInterface => $this->formatDateTime($value),
            is_string($value), $value instanceof \Stringable => "'".static::escapeLiteral((string) $value)."'",
            default => throw new InvalidArgumentException(
                'Cannot use a value of type '.get_debug_type($value).' in a SOQL query.'
            ),
        };
    }

    /**
     * Serialise a value that is compared against a date or dateTime field.
     *
     * Date values are never quoted in SOQL, so strings are only accepted
     * when they are a well-formed date, dateTime or date literal.
     *
     * @param  mixed  $value
     */
    public function dateOperand($value, bool $dateOnly = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof DateTimeInterface) {
            return $dateOnly ? $value->format('Y-m-d') : $this->formatDateTime($value);
        }

        if ($value instanceof Expression) {
            return (string) $value->getValue($this->getQueryGrammar());
        }

        if (is_string($value) && (static::isDateString($value) || static::isDateLiteral($value))) {
            return $value;
        }

        throw new InvalidArgumentException(
            'Invalid value for a SOQL date comparison: expected a DateTimeInterface, an ISO 8601 date or dateTime string, or a SOQL date literal such as LAST_N_DAYS:7.'
        );
    }

    /**
     * Serialise a value that must be a bare integer, such as a calendar year.
     *
     * @param  mixed  $value
     */
    public function integerOperand($value): string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1)) {
            return (string) (int) $value;
        }

        throw new InvalidArgumentException('Invalid value for a SOQL integer comparison.');
    }

    /**
     * Escape a string for use inside a single-quoted SOQL string literal.
     */
    public static function escapeLiteral(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\f" => '\\f',
            "\x08" => '\\b',
        ]);
    }

    /**
     * Whether a string is a SOQL date literal such as TODAY or LAST_N_DAYS:7.
     *
     * @param  mixed  $value
     */
    public static function isDateLiteral($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if (in_array($value, static::DATE_LITERALS, true)) {
            return true;
        }

        return preg_match('/^([A-Z_]+):\d{1,9}$/', $value, $matches) === 1
            && in_array($matches[1], static::DATE_LITERALS_WITH_COUNT, true);
    }

    /**
     * Whether a string is an ISO 8601 date or dateTime as SOQL accepts it.
     *
     * @param  mixed  $value
     */
    public static function isDateString($value): bool
    {
        return is_string($value) && preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:?\d{2}))?$/',
            $value
        ) === 1;
    }

    /**
     * Format a date as a SOQL dateTime literal in UTC.
     */
    protected function formatDateTime(DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format($this->getQueryGrammar()->getDateFormat());
    }

    protected function floatLiteral(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Cannot use a non-finite number in a SOQL query.');
        }

        $literal = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $literal === '' || $literal === '-' ? '0' : $literal;
    }

    /**
     * Escape a value for safe use in a SOQL statement.
     *
     * {@inheritDoc}
     */
    public function escape($value, $binary = false)
    {
        return $this->literal($value);
    }

    /**
     * {@inheritDoc}
     *
     * Bindings are serialised by prepare(), so they are passed on untouched.
     */
    public function prepareBindings(array $bindings)
    {
        return $bindings;
    }

    /**
     * Salesforce has no transactions over the REST query API; callbacks are
     * simply executed.
     *
     * {@inheritDoc}
     */
    public function transaction(\Closure $callback, $attempts = 1)
    {
        return $callback($this);
    }
}
