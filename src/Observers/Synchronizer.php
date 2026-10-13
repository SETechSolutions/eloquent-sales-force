<?php

namespace Lester\EloquentSalesForce\Observers;

class Synchronizer
{
    public function creating($model)
    {
        $model->syncWithSalesforce();
    }

    public function updating($model)
    {
        $model->syncWithSalesforce();
    }
}
