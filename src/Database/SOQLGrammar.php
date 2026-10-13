<?php

namespace SETechSolutions\EloquentSalesForce\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SETechSolutions\EloquentSalesForce\Facades\SObjects;
use SETechSolutions\EloquentSalesForce\Model;

class SOQLGrammar extends Grammar
{
    /**
     * The model being queried, used to tell date fields from other fields.
     *
     * @var \Illuminate\Database\Eloquent\Model|null
     */
    protected $model;

    /**
     * The components that make up a select clause.
     *
     * Joins are compiled before "from" because SOQL expresses them as
     * sub-selects in the field list.
     *
     * @var array<int, string>
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'joins',
        'from',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function setModel($model)
    {
        $this->model = $model;

        return $model;
    }

    /**
     * SOQL identifiers are never quoted.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        return $value;
    }

    /**
     * @param  string  $value
     * @return string
     */
    protected function unWrapValue($value)
    {
        return str_replace('`', '', $value);
    }

    /**
     * {@inheritDoc}
     */
    protected function whereBasic(Builder $query, $where)
    {
        if ($placeholder = $this->datePlaceholder($where['column'], $where['value'])) {
            return $this->wrap($where['column']).' '.$where['operator'].' '.$placeholder;
        }

        if (Str::contains(strtolower($where['operator']), 'not like')) {
            return sprintf(
                '(not %s like %s)',
                $this->wrap($where['column']),
                $this->parameter($where['value'])
            );
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * Compile a "where date" clause.
     *
     * {@inheritDoc}
     */
    protected function whereDate(Builder $query, $where)
    {
        return $this->wrap($where['column']).' '.$where['operator'].' '
            .$this->placeholderOrExpression($where['value'], SOQLConnection::DATE_PLACEHOLDER);
    }

    /**
     * {@inheritDoc}
     */
    protected function whereDay(Builder $query, $where)
    {
        return $this->dateFunctionWhere('DAY_IN_MONTH', $where);
    }

    /**
     * {@inheritDoc}
     */
    protected function whereMonth(Builder $query, $where)
    {
        return $this->dateFunctionWhere('CALENDAR_MONTH', $where);
    }

    /**
     * {@inheritDoc}
     */
    protected function whereYear(Builder $query, $where)
    {
        return $this->dateFunctionWhere('CALENDAR_YEAR', $where);
    }

    /**
     * Compile a comparison against a SOQL date function.
     *
     * @param  array<string, mixed>  $where
     */
    protected function dateFunctionWhere(string $function, array $where): string
    {
        return $function.'('.$this->wrap($where['column']).') '.$where['operator'].' '
            .$this->placeholderOrExpression($where['value'], SOQLConnection::INTEGER_PLACEHOLDER);
    }

    /**
     * {@inheritDoc}
     */
    protected function whereIn(Builder $query, $where)
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' in ('.$this->parameterize($where['values']).')';
        }

        // SOQL rejects an empty IN list; this matches no record instead.
        return 'Id = null';
    }

    /**
     * {@inheritDoc}
     */
    protected function whereNotIn(Builder $query, $where)
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' not in ('.$this->parameterize($where['values']).')';
        }

        // Nothing is excluded, so every record matches.
        return 'Id != null';
    }

    /**
     * {@inheritDoc}
     */
    protected function whereInRaw(Builder $query, $where)
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' in ('.implode(', ', array_map('intval', $where['values'])).')';
        }

        return 'Id = null';
    }

    /**
     * {@inheritDoc}
     */
    protected function whereNotInRaw(Builder $query, $where)
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' not in ('.implode(', ', array_map('intval', $where['values'])).')';
        }

        return 'Id != null';
    }

    /**
     * SOQL has no BETWEEN operator, so a range is compiled as two comparisons.
     *
     * {@inheritDoc}
     */
    protected function whereBetween(Builder $query, $where)
    {
        $column = $this->wrap($where['column']);

        $values = array_values(Arr::flatten((array) $where['values']));

        $min = $this->datePlaceholder($where['column'], $values[0] ?? null) ?: $this->parameter($values[0] ?? null);
        $max = $this->datePlaceholder($where['column'], $values[1] ?? null) ?: $this->parameter($values[1] ?? null);

        return ! empty($where['not'])
            ? "({$column} < {$min} or {$column} > {$max})"
            : "({$column} >= {$min} and {$column} <= {$max})";
    }

    /**
     * {@inheritDoc}
     */
    protected function whereNotNull(Builder $query, $where)
    {
        return $this->wrap($where['column']).' <> NULL';
    }

    /**
     * {@inheritDoc}
     */
    protected function whereNull(Builder $query, $where)
    {
        return $this->wrap($where['column']).' = NULL';
    }

    /**
     * {@inheritDoc}
     */
    protected function compileLimit(Builder $query, $limit)
    {
        return 'limit '.(int) $limit;
    }

    /**
     * Whether a field of the queried model holds a date or dateTime.
     *
     * @param  mixed  $column
     * @return bool
     */
    protected function isDate($column)
    {
        if (! is_string($column) || ! $this->model) {
            return false;
        }

        return $this->model instanceof Model
            ? $this->model->isSalesforceDate($column)
            : in_array($column, $this->model->getDates(), true);
    }

    /**
     * Whether a field of the queried model holds a date without a time.
     *
     * @param  mixed  $column
     */
    protected function isDateOnly($column): bool
    {
        return is_string($column)
            && $this->model instanceof Model
            && $this->model->isSalesforceDateOnly($column);
    }

    /**
     * The placeholder to use when a value must be written as a SOQL date
     * rather than as a quoted string, or null when it is an ordinary value.
     *
     * @param  mixed  $column
     * @param  mixed  $value
     */
    protected function datePlaceholder($column, $value): ?string
    {
        // Expressions are inlined by the query builder and have no binding.
        if ($value instanceof Expression) {
            return null;
        }

        if ($this->isDateOnly($column)) {
            return SOQLConnection::DATE_PLACEHOLDER;
        }

        // Date literals such as THIS_WEEK are honoured on any field, since
        // custom date fields are often not declared on the model.
        if ($this->isDate($column) || SOQLConnection::isDateLiteral($value)) {
            return SOQLConnection::DATETIME_PLACEHOLDER;
        }

        return null;
    }

    /**
     * @param  mixed  $value
     */
    protected function placeholderOrExpression($value, string $placeholder): string
    {
        return $value instanceof Expression ? (string) $this->getValue($value) : $placeholder;
    }

    /**
     * Compile the "join" portions of the query as SOQL child sub-selects.
     *
     * {@inheritDoc}
     */
    protected function compileJoins(Builder $query, $joins)
    {
        return collect($joins)->map(function ($join) use ($query) {
            [$object, $relationship] = $this->resolveChildRelationship($query, $join);

            $columns = collect(SObjects::objectFields($object, $join->columns ?: ['*']))->implode(',');

            $subselect = "select $columns from {$relationship}";

            // The first clause is the join condition, which SOQL infers from
            // the relationship itself.
            Arr::forget($join->wheres, 0);

            if ($join->wheres) {
                $subselect .= ' '.$this->compileWheres($join);
            }

            return ", ($subselect)";
        })->implode(' ');
    }

    /**
     * Work out the child object and the child relationship name of a join.
     *
     * The join may name the child object ("Contact"), the relationship
     * ("Contacts", "Children__r") or both ("Contact as Contacts"). The
     * missing half is looked up in the parent's describe result, and the
     * old guess (the plural of the object name) is the last resort.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveChildRelationship(Builder $query, $join): array
    {
        $table = $this->unWrapValue((string) $join->table);

        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $table, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        $field = $join->wheres[0]['first'] ?? null;
        $field = is_string($field) ? Str::afterLast($field, '.') : null;

        foreach ($this->childRelationships($query) as $child) {
            $object = $child['childSObject'] ?? null;
            $name = $child['relationshipName'] ?? null;

            if (! $object || ! $name) {
                continue;
            }

            if (strcasecmp($name, $table) === 0) {
                return [$object, $name];
            }

            if (strcasecmp($object, $table) === 0 && (! $field || strcasecmp((string) ($child['field'] ?? ''), $field) === 0)) {
                return [$object, $name];
            }
        }

        return [$table, $this->grammarPlural($table)];
    }

    /**
     * The child relationships of the queried object.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function childRelationships(Builder $query): array
    {
        $from = is_string($query->from) ? $query->from : null;

        if (! $from) {
            return [];
        }

        try {
            return (array) (SObjects::describe($from)['childRelationships'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function concatenateWhereClauses($query, $sql)
    {
        return 'where '.$this->removeLeadingBoolean(implode(' ', $sql));
    }

    /**
     * {@inheritDoc}
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        $column = $this->columnize($aggregate['columns']);

        // SOQL has no COUNT(*); counting the record ID is the equivalent.
        if ($column === '*') {
            $column = 'Id';
        } elseif (is_array($query->distinct)) {
            $column = 'distinct '.$this->columnize($query->distinct);
        } elseif ($query->distinct) {
            $column = 'distinct '.$column;
        }

        return 'select '.$aggregate['function'].'('.$column.') aggregate';
    }

    /**
     * The name of the child relationship for an object: its plural.
     *
     * @param  string  $string
     * @return string
     */
    private function grammarPlural($string)
    {
        if (Str::endsWith($string, 'try')) {
            return Str::replaceLast('try', 'tries', $string);
        }

        return Str::plural($string);
    }

    /**
     * Get the format for dateTime values in a query.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d\TH:i:s\Z';
    }

    /**
     * {@inheritDoc}
     */
    public function compileLock(Builder $query, $value)
    {
        return 'FOR UPDATE';
    }
}
