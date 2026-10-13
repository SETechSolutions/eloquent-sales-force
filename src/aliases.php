<?php

/*
 * Keeps the class names of rob-lester-jr04/eloquent-sales-force working.
 *
 * Nothing happens until a Lester\EloquentSalesForce\* name is first used.
 * At that point every class of the 2.x releases is aliased at once, so that
 * type declarations naming an old class match objects of the new one even
 * when that particular name was never autoloaded itself.
 */

spl_autoload_register(static function (string $class): void {
    static $registered = false;

    $legacy = 'Lester\\EloquentSalesForce\\';

    if ($registered || strncmp($class, $legacy, strlen($legacy)) !== 0) {
        return;
    }

    $registered = true;

    $current = 'SETechSolutions\\EloquentSalesForce\\';

    $classes = [
        'Console\\MakeModelCommand' => 'Console\\MakeModelCommand',
        'Console\\SyncFromSalesforce' => 'Console\\SyncFromSalesforce',
        'Database\\SOQLBatch' => 'Database\\SOQLBatch',
        'Database\\SOQLBuilder' => 'Database\\SOQLBuilder',
        'Database\\SOQLConnection' => 'Database\\SOQLConnection',
        'Database\\SOQLGrammar' => 'Database\\SOQLGrammar',
        'Database\\SOQLHasMany' => 'Database\\SOQLHasMany',
        'Database\\SOQLHasOne' => 'Database\\SOQLHasOne',
        'Database\\SOQLHasOneOrMany' => 'Database\\SOQLHasOneOrMany',
        'Exceptions\\MalformedQueryException' => 'Exceptions\\MalformedQueryException',
        'Exceptions\\RequestLimitExceeded' => 'Exceptions\\RequestLimitExceeded',
        'Exceptions\\RestAPIException' => 'Exceptions\\RestAPIException',
        'Exceptions\\UnableToLockRowException' => 'Exceptions\\UnableToLockRowException',
        'Facades\\SObjects' => 'Facades\\SObjects',
        'Fakers\\SObjectsFake' => 'Fakers\\SObjectsFake',
        'Model' => 'Model',
        'Observers\\Syncronizer' => 'Observers\\Synchronizer',
        'SObjects' => 'SObjects',
        'SalesForceObject' => 'SalesForceObject',
        'ServiceProvider' => 'ServiceProvider',
        'Traits\\SyncsWithSalesforce' => 'Traits\\SyncsWithSalesforce',
    ];

    foreach ($classes as $old => $new) {
        if (! class_exists($legacy.$old, false) && ! trait_exists($legacy.$old, false)) {
            class_alias($current.$new, $legacy.$old);
        }
    }
});
