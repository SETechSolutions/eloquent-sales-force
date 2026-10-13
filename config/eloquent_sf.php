<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default fields
    |--------------------------------------------------------------------------
    |
    | When a model does not list its fields in the $columns property, the
    | fields of this layout are selected. Listing the fields on the model
    | avoids the extra describe call this requires.
    |
    */

    'layout' => 'describe/compactLayouts/primary',

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The log channel that queries and write operations are logged to at the
    | "info" level. Set SOQL_LOG=false to turn this logging off.
    |
    */

    'logging' => env('SOQL_LOG', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Batch sizes
    |--------------------------------------------------------------------------
    |
    | Salesforce accepts at most 25 queries per batch request and 200 records
    | per bulk insert, update or delete request. Larger values are capped.
    |
    */

    'batch' => [
        'select' => [
            'size' => 25,
        ],
        'insert' => [
            'size' => 200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Objects without IsDeleted
    |--------------------------------------------------------------------------
    |
    | IsDeleted is selected for every object except the ones listed here.
    |
    */

    'noSoftDeletesOn' => [
        'User',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local model sync
    |--------------------------------------------------------------------------
    |
    | Settings for models using the SyncsWithSalesforce trait.
    |
    | syncTwoWay:       also copy changes made in Salesforce to the local
    |                   model. When false, changes only flow local -> Salesforce.
    | syncPriority:     what to do when the local model has unsaved changes
    |                   while Salesforce holds a newer version: "salesforce"
    |                   (Salesforce wins), "local" (local wins), "silent"
    |                   (skip the sync) or "exception" (throw).
    | syncTwoWayModels: the local model classes handled by the
    |                   salesforce:sync command and the sync webhook.
    |
    */

    'syncTwoWay' => false,
    'syncPriority' => 'salesforce',
    'syncTwoWayModels' => [

    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | login: GET /login/salesforce and its callback, used by the WebServer
    |        OAuth flow. "enabled" => null registers them only when the
    |        authentication method is WebServer.
    |
    | sync:  POST /api/syncObject/{id}, which Salesforce can call to push a
    |        change to the local models listed in syncTwoWayModels.
    |        "enabled" => null registers it only when syncTwoWay is on.
    |        Requests must carry the shared secret in the
    |        X-Salesforce-Sync-Secret header; without a configured secret
    |        every request is rejected. Set "secret" to false only if the
    |        middleware you list here authenticates the request itself.
    |
    */

    'routes' => [
        'login' => [
            'enabled' => null,
            'middleware' => ['web'],
            'redirectTo' => '/',
        ],
        'sync' => [
            'enabled' => null,
            'middleware' => ['api'],
            'secret' => env('SF_SYNC_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Forrest
    |--------------------------------------------------------------------------
    |
    | The settings of omniphx/forrest, the Salesforce REST client this
    | package is built on. Forrest's own config file is ignored: every
    | Forrest setting is read from here.
    |
    */

    'forrest' => [

        /*
         * WebServer, UserPassword, UserPasswordSoap, ClientCredentials or OAuthJWT.
         */
        'authentication' => env('SF_AUTH_METHOD', 'UserPassword'),

        /*
         * username and password are only needed for the UserPassword flows,
         * callbackURI only for WebServer, privateKey only for OAuthJWT.
         *
         * A "soql" entry in config/database.php connections, as older
         * releases asked for, is still honoured and takes precedence.
         */
        'credentials' => [
            'consumerKey' => env('SF_CONSUMER_KEY'),
            'consumerSecret' => env('SF_CONSUMER_SECRET'),
            'callbackURI' => env('SF_CALLBACK_URI'),
            'loginURL' => env('SF_LOGIN_URL', 'https://login.salesforce.com'),
            'username' => env('SF_USERNAME'),
            // Append the security token to the password unless your IP address is trusted.
            'password' => env('SF_PASSWORD'),
            'privateKey' => env('SF_PRIVATE_KEY', ''),
        ],

        /*
         * Optional parameters for the WebServer flow.
         */
        'parameters' => [
            'display' => 'popup',
            'immediate' => false,
            'state' => '',
            'scope' => 'full',
            'prompt' => 'select_account',
        ],

        /*
         * Default settings for resource requests.
         * Format can be 'json', 'xml' or 'none'.
         * Compression can be set to 'gzip' or 'deflate'.
         */
        'defaults' => [
            'method' => 'get',
            'format' => 'json',
            'compression' => false,
            'compressionType' => 'gzip',
        ],

        /*
         * Options for the underlying Guzzle HTTP client.
         */
        'client' => [
            'http_errors' => true,
            'verify' => true,
        ],

        /*
         * Where the access token fetched from Salesforce is kept.
         */
        'storage' => [
            'type' => 'cache', // 'session', 'cache' or 'object'
            'path' => 'forrest_', // unique storage path to avoid collisions
            'expire_in' => 3600, // number of seconds before the token is fetched again
            'store_forever' => false, // never expire the stored token
        ],

        /*
         * The API version to use, e.g. '62.0'. Leave empty for the latest.
         */
        'version' => '',

        /*
         * Optional: override the instance URL returned by Salesforce.
         */
        'instanceURL' => '',

        'language' => 'en_US',
    ],

];
