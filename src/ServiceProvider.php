<?php

namespace Lester\EloquentSalesForce;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Route;
use Lester\EloquentSalesForce\Clients\ForrestClient;
use Lester\EloquentSalesForce\Console\MakeModelCommand;
use Lester\EloquentSalesForce\Console\SyncFromSalesforce;
use Lester\EloquentSalesForce\Contracts\SalesforceClient;
use Lester\EloquentSalesForce\Database\SOQLConnection;
use Lester\EloquentSalesForce\Facades\SObjects as SObjectsFacade;
use Lester\EloquentSalesForce\Http\Controllers\LoginController;
use Lester\EloquentSalesForce\Http\Controllers\SyncController;
use Lester\EloquentSalesForce\Http\Middleware\VerifySyncSecret;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    const CONFIG_PATH = __DIR__.'/../config/eloquent_sf.php';

    /**
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('eloquent_sf.php'),
        ], ['config', 'eloquent-sf-config']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeModelCommand::class,
                SyncFromSalesforce::class,
            ]);
        }

        $this->registerRoutes();
    }

    /**
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'eloquent_sf');

        $this->configureForrest();

        $this->app->register(ForrestServiceProvider::class);

        $this->app->singleton(SalesforceClient::class, fn ($app) => new ForrestClient($app));

        $this->app->singleton('sobjects', fn () => new SObjects);

        $this->app->singleton(SOQLConnection::class, fn () => new SOQLConnection);

        $loader = AliasLoader::getInstance();
        $loader->alias('Forrest', Forrest::class);
        $loader->alias('SObjects', SObjectsFacade::class);
    }

    /**
     * Hand the package's Forrest settings to Forrest.
     */
    protected function configureForrest(): void
    {
        $config = $this->app->make('config');

        $forrest = (array) $config->get('eloquent_sf.forrest', []);

        // Older releases read the credentials from a "soql" database
        // connection; keep honouring it so existing installs keep working.
        $legacy = array_filter(
            (array) $config->get('database.connections.soql', []),
            fn ($value) => $value !== null && $value !== ''
        );

        unset($legacy['driver'], $legacy['database']);

        $forrest['credentials'] = array_merge((array) ($forrest['credentials'] ?? []), $legacy);

        $config->set('eloquent_sf.forrest', $forrest);
        $config->set('forrest', $forrest);
    }

    /**
     * Register the OAuth login routes and the sync webhook.
     */
    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');

        $login = $config->get('eloquent_sf.routes.login.enabled');

        if ($login ?? $config->get('eloquent_sf.forrest.authentication') === 'WebServer') {
            Route::middleware($config->get('eloquent_sf.routes.login.middleware', ['web']))->group(function () {
                Route::get('/login/salesforce', [LoginController::class, 'redirect'])->name('eloquent-sf.login');
                Route::get('/login/salesforce/callback', [LoginController::class, 'callback'])->name('eloquent-sf.callback');
            });
        }

        $sync = $config->get('eloquent_sf.routes.sync.enabled');

        if ($sync ?? (bool) $config->get('eloquent_sf.syncTwoWay')) {
            Route::post('/api/syncObject/{sfid}', SyncController::class)
                ->middleware(array_merge(
                    (array) $config->get('eloquent_sf.routes.sync.middleware', ['api']),
                    [VerifySyncSecret::class]
                ))
                ->name('eloquent-sf.sync');
        }
    }

    /**
     * The fields to select for an object when no explicit columns are given.
     *
     * @deprecated Use SObjects::objectFields().
     *
     * @param  string  $table
     * @param  array<int, mixed>  $columns
     * @return array<int, mixed>
     */
    public static function objectFields($table, $columns)
    {
        return SObjectsFacade::objectFields($table, $columns);
    }
}
