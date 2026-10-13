<?php

namespace SETechSolutions\EloquentSalesForce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    /**
     * Sync the local models linked to a Salesforce record.
     *
     * @param  string  $sfid
     */
    public function __invoke($sfid): JsonResponse
    {
        $synced = 0;

        foreach (config('eloquent_sf.syncTwoWayModels', []) as $class) {
            $models = $class::where((new $class)->getSalesforceIdField(), $sfid)->get();

            foreach ($models as $model) {
                $model->syncWithSalesforce();

                $synced++;
            }
        }

        return new JsonResponse(['synced' => $synced]);
    }
}
