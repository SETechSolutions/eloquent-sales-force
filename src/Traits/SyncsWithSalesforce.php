<?php

namespace SETechSolutions\EloquentSalesForce\Traits;

use Illuminate\Support\Str;
use SETechSolutions\EloquentSalesForce\Observers\Synchronizer;
use SETechSolutions\EloquentSalesForce\SalesForceObject;

trait SyncsWithSalesforce
{
    private $tempSyncObject = null;

    public static function bootSyncsWithSalesforce()
    {
        $observer = new Synchronizer;

        static::creating(fn ($model) => $observer->creating($model));
        static::updating(fn ($model) => $observer->updating($model));
    }

    public function syncWithSalesforce()
    {
        $object = $this->syncTwoWay();

        // The record is read once per sync, so the next sync sees fresh data.
        $this->tempSyncObject = null;

        if ($object) {
            // Unsaved local changes while Salesforce holds a newer version.
            $conflict = $this->isDirty();

            switch ($conflict ? config('eloquent_sf.syncPriority', 'salesforce') : 'salesforce') {
                case 'exception':
                    throw new \Exception('Sync Conflict');
                case 'silent':
                    return $this;
                case 'local':
                    // Keep the local values; they are pushed below.
                    break;
                default:
                    $this->syncSalesforceToLocal($object);
            }
        }

        return $this->syncLocalToSalesforce();
    }

    public function syncLocalToSalesforce()
    {
        $id = $this->{$this->getSalesforceIdField()};
        $values = $this->getSalesforceSyncedValues();

        $sfModel = new SalesForceObject([
            'attributes' => [
                'type' => $this->getSalesforceObjectName(),
            ],
        ]);

        if ($id === null) {
            // Nothing to look up: the record does not exist in Salesforce yet.
            $sfModel = tap($sfModel->newInstance($values))->save();
        } else {
            $sfModel = $sfModel->newQuery()
                ->select(array_merge(['Id'], array_keys($values)))
                ->updateOrCreate(['Id' => $id], $values);
        }

        $this->{$this->getSalesforceIdField()} = $sfModel->Id;

        return $this;
    }

    public function syncSalesforceToLocal($object)
    {
        foreach ($this->getSalesforceMapping() as $sfKey => $modelKey) {
            $this->{$modelKey} = $object->{$sfKey};
        }
        $this->withoutEvents(function () {
            $this->save();
        });

        return $this;
    }

    public function getSalesforceObjectName()
    {
        return $this->salesForceObject ?? Str::afterLast(self::class, '\\');
    }

    public function getSalesforceMapping()
    {
        return $this->salesForceFieldMap ?? [];
    }

    public function getSalesforceSyncedValues()
    {
        $values = collect([]);
        foreach ($this->getSalesforceMapping() as $sfKey => $modelKey) {
            if ($modelKey instanceof \Closure) {
                $values->put($sfKey, $modelKey($this));
            } else {
                $values->put($sfKey, $this->$modelKey);
            }
        }

        return $values->toArray();
    }

    public function getSalesforceIdField()
    {
        return $this->salesForceIdField ?? 'salesforce';
    }

    public function getSalesforceDates()
    {
        return $this->salesForceDates ?? [
            'created_at' => 'CreatedDate',
            'updated_at' => 'LastModifiedDate',
        ];
    }

    private function syncTempObject()
    {
        if ($this->tempSyncObject === null) {
            $sfModel = new SalesForceObject([
                'attributes' => [
                    'type' => $this->getSalesforceObjectName(),
                ],
            ]);

            // Select exactly the mapped fields; the object's default fields
            // need not include them.
            $this->tempSyncObject = $sfModel->newQuery()
                ->select(array_values(array_unique(array_merge(
                    ['Id', 'LastModifiedDate'],
                    array_keys($this->getSalesforceMapping())
                ))))
                ->where('Id', $this->{$this->getSalesforceIdField()})
                ->first() ?: false;
        }

        return $this->tempSyncObject ?: null;
    }

    public function syncTwoWay()
    {
        if (config('eloquent_sf.syncTwoWay') && ($id = $this->{$this->getSalesforceIdField()}) !== null) {
            $sfModel = $this->syncTempObject();

            return $sfModel && $this->syncSalesforceIsNewer($sfModel) ? $sfModel : false;
        }

        return false;
    }

    public function syncDifferences($object)
    {
        $remote = $object->only(array_keys($this->getSalesforceMapping()));

        return array_keys(array_filter(
            $this->getSalesforceSyncedValues(),
            fn ($value, $field) => ($remote[$field] ?? null) != $value,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    public function syncSalesforceIsNewer($object)
    {
        $remote = $object->LastModifiedDate;

        if (! $remote || ! $this->updated_at) {
            return (bool) $remote;
        }

        $remote = $remote instanceof \DateTimeInterface ? $remote->getTimestamp() : strtotime((string) $remote);

        return $remote > $this->updated_at->getTimestamp();
    }
}
