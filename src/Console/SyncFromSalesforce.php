<?php

namespace Lester\EloquentSalesForce\Console;

use Illuminate\Console\Command;

class SyncFromSalesforce extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'salesforce:sync';

    /**
     * The console command name aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['db:sync'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger a sync of all connected local and SF objects.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        foreach ($this->getModels() as $class) {
            $collection = $class::whereNotNull((new $class)->getSalesforceIdField())->get();

            foreach ($collection as $model) {
                $model->syncWithSalesforce();
            }
        }

        return self::SUCCESS;
    }

    private function getModels()
    {
        return config('eloquent_sf.syncTwoWayModels', []);
    }

}
