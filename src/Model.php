<?php

namespace Lester\EloquentSalesForce;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lester\EloquentSalesForce\Database\SOQLBuilder as Builder;
use Lester\EloquentSalesForce\Database\SOQLConnection;
use Lester\EloquentSalesForce\Database\SOQLHasMany as HasMany;
use Lester\EloquentSalesForce\Database\SOQLHasOne as HasOne;
use Lester\EloquentSalesForce\Facades\SObjects;
use Throwable;

/**
 * @property string|null $Id
 * @property array<string, mixed>|null $sf_attributes
 * @property string|null $web_link
 */
abstract class Model extends EloquentModel
{
    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'CreatedDate';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'LastModifiedDate';

    protected $guarded = [];

    /**
     * Fields that are never sent to Salesforce on insert or update.
     *
     * @var array<int, string>
     */
    protected $readonly = [];

    protected $dateFormat = 'Y-m-d\TH:i:s.vO';

    /**
     * Fields holding a date or dateTime.
     *
     * Declaring a "date" or "datetime" cast works just as well.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'CreatedDate',
        'LastModifiedDate',
    ];

    /**
     * Date fields that hold a date without a time (the Salesforce "Date"
     * type), which are written and compared as YYYY-MM-DD.
     *
     * @var array<int, string>
     */
    protected $shortDates = [];

    /**
     * Extra HTTP headers sent with insert and update requests.
     *
     * @var array<string, string>
     */
    public $custom_headers = [];

    public $incrementing = false;

    /**
     * The fields selected when a query does not name any.
     *
     * When empty, the fields of the layout in eloquent_sf.layout are used.
     *
     * @var array<int, string>
     */
    public $columns = [];

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'Id';

    /**
     * The "type" of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $table
     */
    public function __construct(array $attributes = [], $table = null)
    {
        parent::__construct($attributes);

        if (isset($attributes['Id'])) {
            $this->exists = true;
        }

        $this->table = $table ?: $this->table ?: class_basename($this);

        $this->attributes['attributes'] = [
            'type' => $this->table,
        ];
    }

    /**
     * The model's attributes without its read-only fields.
     *
     * @param  array<int, string>  $exclude
     * @return array<string, mixed>
     */
    public function writeableAttributes($exclude = [])
    {
        return Arr::except($this->attributes, array_merge($this->readonly, $exclude));
    }

    /**
     * The changed attributes in the form Salesforce expects them.
     *
     * @return array<string, mixed>
     */
    public function getSalesforceDirty()
    {
        return $this->toSalesforceValues($this->getDirty());
    }

    /**
     * Prepare attributes for a write request: drops the record ID, the
     * metadata and read-only fields, and formats dates.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function toSalesforceValues(array $attributes)
    {
        $attributes = Arr::except($attributes, array_merge(['Id', 'attributes'], $this->readonly));

        foreach ($attributes as $key => $value) {
            if ($value !== null && $value !== '' && $this->isSalesforceDate($key)) {
                $attributes[$key] = $this->asDateTime($value)->format(
                    $this->isSalesforceDateOnly($key) ? 'Y-m-d' : $this->getDateFormat()
                );
            }
        }

        return $attributes;
    }

    /**
     * Whether a field holds a date or dateTime.
     *
     * @param  string  $key
     */
    public function isSalesforceDate($key): bool
    {
        return $this->isDateAttribute($key);
    }

    /**
     * Whether a field holds a date without a time.
     *
     * @param  string  $key
     */
    public function isSalesforceDateOnly($key): bool
    {
        if (in_array($key, $this->getShortDates(), true)) {
            return true;
        }

        $cast = $this->getCasts()[$key] ?? null;

        return is_string($cast) && preg_match('/^(immutable_)?date(:|$)/', $cast) === 1;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, string>
     */
    public function getDates()
    {
        return array_values(array_unique(array_merge(
            parent::getDates(),
            $this->dates ?? [],
            $this->getShortDates()
        )));
    }

    /**
     * The date fields that hold a date without a time.
     *
     * @return array<int, string>
     */
    public function getShortDates()
    {
        return $this->shortDates ?? [];
    }

    /**
     * Whether queries for this object can select and filter on IsDeleted.
     *
     * Override this, or list the object in eloquent_sf.noSoftDeletesOn, for
     * objects that have no IsDeleted field.
     */
    public function usesSalesforceSoftDeletes(): bool
    {
        return ! in_array($this->getTable(), (array) config('eloquent_sf.noSoftDeletesOn', ['User']), true);
    }

    /**
     * Delete the record from Salesforce.
     *
     * @return bool
     */
    public function delete()
    {
        if (is_null($this->Id)) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        try {
            SObjects::sobjects($this->table.'/'.$this->Id, [
                'method' => 'delete',
            ]);
        } catch (Throwable $e) {
            SObjects::log("{$this->table} object {$this->Id} failed to delete.", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], 'warning');

            return false;
        }

        SObjects::queryHistory()->push(['delete' => [$this->Id]]);

        SObjects::log("{$this->table} object {$this->Id} deleted.");

        $this->exists = false;

        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function forceDelete()
    {
        return $this->delete();
    }

    /**
     * Perform a model insert operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performInsert($query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        $attributes = $this->getSalesforceDirty();

        $result = SObjects::sobjects($this->table, $this->writeOptions('post', $attributes));

        SObjects::queryHistory()->push(['insert' => $attributes]);

        if (empty($result['success'])) {
            return false;
        }

        if (isset($result['id'])) {
            $this->setAttribute('Id', $result['id']);
        }

        // The record now exists, so that an update made from a "created"
        // listener is sent as an update.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Perform a model update operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performUpdate($query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $dirty = $this->getSalesforceDirty();

        if (count($dirty) > 0) {
            SObjects::sobjects($this->table.'/'.$this->Id, $this->writeOptions('patch', $dirty));

            SObjects::queryHistory()->push(['update' => $dirty]);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * The request options for an insert or update.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function writeOptions(string $method, array $body): array
    {
        $options = ['method' => $method, 'body' => $body];

        if (! empty($this->custom_headers)) {
            $options['headers'] = $this->custom_headers;
        }

        return $options;
    }

    /**
     * Mark a model as stored after a bulk insert returned its ID.
     *
     * @param  string  $id
     * @return $this
     */
    public function markAsInserted($id)
    {
        $this->setAttribute('Id', $id);

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        return $this->syncOriginal();
    }

    /**
     * A shared connection for anything that inspects the model's
     * connection; each query gets a connection of its own.
     *
     * @return SOQLConnection
     */
    public function getConnection()
    {
        return app()->bound(SOQLConnection::class)
            ? app(SOQLConnection::class)
            : new SOQLConnection;
    }

    /**
     * {@inheritDoc}
     */
    protected function newBaseQueryBuilder()
    {
        return (new SOQLConnection)->query();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * Define a one-to-many relationship.
     *
     * @param  string  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @return HasMany
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return $this->newSOQLHasMany(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    /**
     * Define a one-to-one relationship.
     *
     * @param  string  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @return HasOne
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return $this->newSOQLHasOne(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * @param  string  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $ownerKey
     * @param  string|null  $relation
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // Salesforce lookup fields are named after the relationship followed
        // by the key, e.g. "AccountId" for an "account" relationship.
        if (is_null($foreignKey)) {
            $foreignKey = ucwords(Str::camel($relation.'_'.$instance->getKeyName()));
        }

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $this->newBelongsTo(
            $instance->newQuery(), $this, $foreignKey, $ownerKey, $relation
        );
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasMany
     */
    protected function newSOQLHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Instantiate a new HasOne relationship.
     *
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasOne
     */
    protected function newSOQLHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new HasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Get the default foreign key name for the model, e.g. "AccountId".
     *
     * @return string
     */
    public function getForeignKey()
    {
        return Str::studly(class_basename($this)).$this->getKeyName();
    }

    /**
     * The record's Salesforce metadata (type and URL).
     *
     * @return array<string, mixed>|null
     */
    public function getSfAttributesAttribute()
    {
        return Arr::get($this->attributes, 'attributes');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return void
     */
    public function setSfAttributesAttribute(array $value)
    {
        $this->attributes['attributes'] = $value;
    }

    /**
     * A link to the record in the Salesforce UI.
     *
     * @return string|null
     */
    public function getWebLinkAttribute()
    {
        $instance = SObjects::instanceUrl();

        return $instance && $this->Id ? rtrim($instance, '/').Str::start($this->Id, '/') : null;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string) Arr::get($this->attributes, 'Id');
    }

    /**
     * The default fields selected for this model.
     *
     * @return array<int, string>
     */
    public static function columns()
    {
        return (new static([]))->columns;
    }

    /**
     * Whether the record is in the recycle bin.
     *
     * @return bool
     */
    public function trashed()
    {
        return (bool) ($this->IsDeleted ?? false);
    }

    /**
     * @return never
     *
     * @throws \Exception
     */
    public function restore()
    {
        throw new \Exception('The SalesForce Rest API does not natively support UNDELETE');
    }

    /**
     * @return bool
     */
    public function getExistsAttribute()
    {
        return $this->Id !== null;
    }
}
