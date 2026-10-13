<?php

namespace Lester\EloquentSalesForce;

/**
 * An anonymous model for any Salesforce object, for use without a
 * dedicated model class:
 *
 *     SalesForceObject::select('Id')->from('Lead')->first();
 */
class SalesForceObject extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $table
     */
    public function __construct(array $attributes = [], $table = null)
    {
        if ($table = $table ?: ($attributes['attributes']['type'] ?? null)) {
            $this->setTable($table);
        }

        parent::__construct($attributes);
    }

    /**
     * @param  string  $tableName
     * @return $this
     */
    public function setTable($tableName)
    {
        $this->attributes['attributes'] = [
            'type' => $tableName,
        ];

        return parent::setTable($tableName);
    }
}
