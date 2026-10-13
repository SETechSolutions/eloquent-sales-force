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
     */
    public function __construct(array $attributes = [])
    {
        if (isset($attributes['attributes']['type'])) {
            $this->setTable($attributes['attributes']['type']);
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
