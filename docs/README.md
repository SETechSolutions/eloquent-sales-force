# Eloquent Salesforce

[![Tests](https://github.com/SETechSolutions/eloquent-sales-force/actions/workflows/tests.yml/badge.svg)](https://github.com/SETechSolutions/eloquent-sales-force/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/setechsolutions/eloquent-sales-force.svg)](https://packagist.org/packages/setechsolutions/eloquent-sales-force)
[![License](https://img.shields.io/packagist/l/setechsolutions/eloquent-sales-force.svg)](https://packagist.org/packages/setechsolutions/eloquent-sales-force)

## Overview

Eloquent Salesforce lets you work with Salesforce objects the same way you work with Eloquent models in your own database. Queries are translated to SOQL and sent to the Salesforce REST API, and records come back as models.

This is the maintained continuation of [roblesterjr04/EloquentSalesForce](https://github.com/roblesterjr04/EloquentSalesForce), also known as ElSF.

## Upgrading from the original package

Version 3 replaces `rob-lester-jr04/eloquent-sales-force` and keeps its class names, config file and facade working. See the [upgrade guide](https://github.com/SETechSolutions/eloquent-sales-force/blob/main/UPGRADE.md) for the steps and the behaviour changes.

# Getting Started

## Requirements

- PHP 8.2 or newer (8.3 or newer for Laravel 13)
- Laravel 12 or 13

## Installation

```bash
composer require setechsolutions/eloquent-sales-force
```

> A fresh Laravel 13 application ships with Guzzle 8, while the Salesforce client this package builds on still needs Guzzle 7. If Composer reports a conflict on `guzzlehttp/guzzle`, run the command with `-W` so it may switch to Guzzle 7, which Laravel 13 supports as well.

## Setting up your connected app

1. Log in to your Salesforce org and open Setup.
2. Go to **App Manager** and choose **New Connected App**.
3. Fill in the name, API name and contact email.
4. Enable **OAuth Settings**, enter a callback URL and select the access scopes you need. Select the refresh token scope if you use the WebServer flow.
5. Save.

Salesforce then shows you a Consumer Key and a Consumer Secret.

## Configuration

Add the credentials to your `.env` file:

```dotenv
SF_CONSUMER_KEY=
SF_CONSUMER_SECRET=
SF_LOGIN_URL=https://login.salesforce.com

# Only for the UserPassword flow, which is the default.
SF_USERNAME=
# Append your security token to the password unless your IP address is trusted.
SF_PASSWORD=
```

Use `https://test.salesforce.com` as the login URL for a sandbox.

To choose another authentication flow, set `SF_AUTH_METHOD` to `WebServer`, `UserPasswordSoap`, `ClientCredentials` or `OAuthJWT`.

To change any other setting, publish the config file:

```bash
php artisan vendor:publish --tag=eloquent-sf-config
```

It is published as `config/eloquent_sf.php`. Its `forrest` section accepts every setting of [omniphx/forrest](https://github.com/omniphx/forrest), the REST client underneath.

> A `soql` entry in the `connections` array of `config/database.php`, which earlier versions asked for, still works and takes precedence over the environment variables.

## Model Setup

Generate a model:

```bash
php artisan make:salesforce Lead
```

Or write one by hand. It is an Eloquent model that extends the package's base model:

```php
<?php

namespace App\Models;

use SETechSolutions\EloquentSalesForce\Model;

class Lead extends Model
{
    //
}
```

The class name is used as the Salesforce object name. Set `$table` if they differ; see [Custom Objects](#custom-objects).

# The Basics

Define a model as shown above and treat it like any Eloquent model.

## Queries

```php
$leads = Lead::where('Email', 'user@test.com')->get();

$lead = Lead::find('00Q1J00000cQ08eUAC');

$email = $lead->Email;
```

## Insert

```php
$lead = new Lead();
$lead->FirstName = 'Foo';
$lead->LastName = 'Bar';
$lead->Company = 'Test';
$lead->save();
```

Or:

```php
$lead = Lead::create([
    'FirstName' => 'Foo',
    'LastName' => 'Bar',
    'Company' => 'Test Company',
]);
```

## Update

```php
$lead = Lead::first();
$lead->LastName = 'Lester';
$lead->save();
```

Or:

```php
$lead->update([
    'LastName' => 'Lester',
]);
```

Only the fields that changed are sent.

## Delete

```php
$lead = Lead::first();

$lead->delete();
```

`delete()` returns `false` when Salesforce refuses the deletion.

# Advanced Usage

## Observers

Model events and [observers](https://laravel.com/docs/eloquent#observers) work as they do in Laravel. The `creating`, `created`, `updating`, `updated`, `deleting` and `deleted` events are fired.

```php
/* Lead model */
public static function booted()
{
    // Set the phone number when the model is created.
    static::creating(function ($lead) {
        $lead->Phone = '1231231234';
    });
}
```

## Soft Deletes

Salesforce moves deleted records to the recycle bin. The usual Eloquent methods query them:

```php
/* Include deleted records */
Lead::withTrashed()->where('Email', 'email@test.com')->get();

/* Check whether a record is deleted */
$lead->trashed();

/* Only deleted records */
Lead::onlyTrashed()->get();
```

Salesforce cannot restore a record through the REST API, so `restore()` throws an exception.

Some objects, such as `User`, have no `IsDeleted` field. List them in `noSoftDeletesOn` in the config file, or override `usesSalesforceSoftDeletes()` on the model to return `false`.

## Columns

SOQL has no `SELECT *`. When a query does not name its fields, the package selects the fields of the object's compact layout, which costs an extra API call per query.

To avoid that call, and to control which fields you get, list them on the model:

```php
public $columns = [
    'Id',
    'Name',
    'Email',
];
```

`CreatedDate`, `LastModifiedDate` and `IsDeleted` are added for you. Set `public $timestamps = false;` on the model if the object has no `CreatedDate` and `LastModifiedDate` fields.

You can also choose fields per query:

```php
$contacts = Contact::select('Id', 'Name')->get();
```

To see which fields a model selects by default:

```php
$fields = Lead::describe();
```

## Dates

`CreatedDate` and `LastModifiedDate` take the place of Laravel's `created_at` and `updated_at`. They are read as Carbon instances.

Salesforce is strict about how dates are written in queries: they are not quoted, and a `Date` field takes a different format than a `DateTime` field. The package handles this once it knows which fields hold dates. Declare them with casts:

```php
protected $casts = [
    'Last_Contacted__c' => 'datetime', // a DateTime field
    'Birthdate' => 'date',             // a Date field
];
```

The `$dates` and `$shortDates` properties of earlier versions still work. `$dates` lists date and dateTime fields, `$shortDates` the ones that hold a date without a time:

```php
protected $dates = [
    'CreatedDate',
    'LastModifiedDate',
    'Last_Contacted__c',
    'Birthdate',
];

protected $shortDates = [
    'Birthdate',
];
```

With that in place, pass dates as you would anywhere else:

```php
Lead::where('CreatedDate', '>=', today())->get();

Contact::where('Birthdate', '<', now()->subYears(18))->get();

Lead::create([
    'LastName' => 'Bar',
    'Last_Contacted__c' => now(),
]);
```

DateTime values are converted to UTC. On a field that is not declared, a `Carbon` or `DateTime` value is still written as a dateTime; use `whereDate()` to compare an undeclared `Date` field:

```php
Lead::whereDate('Custom_Date_Field__c', '>', today())->get();
```

[SOQL date literals](https://developer.salesforce.com/docs/atlas.en-us.soql_sosl.meta/soql_sosl/sforce_api_calls_soql_select_dateformats.htm) can be used as values:

```php
Lead::where('CreatedDate', 'THIS_WEEK')->get();

Lead::where('CreatedDate', '>', 'LAST_N_DAYS:30')->get();
```

`whereYear()`, `whereMonth()` and `whereDay()` use the SOQL calendar functions. Salesforce has no time-only type, so `whereTime()` behaves like `where()`.

## Where / Order By

```php
$contact = Contact::where('Email', 'test@test.com')->first();

$contacts = Contact::where('Name', 'like', 'Donuts%')->get();

$contacts = Contact::whereIn('Title', ['CEO', 'CTO'])->orderBy('Name')->limit(20)->get();

$count = Contact::where('MailingCountry', 'France')->count();

$exists = Contact::where('Email', 'test@test.com')->exists();
```

You can filter on fields of a parent record with dot notation:

```php
Contact::where('Account.Industry', 'Banking')->get();
```

Values are escaped for you. Never build a condition by concatenating user input into `whereRaw()`; pass it as a binding instead:

```php
Contact::whereRaw('Name like ?', [$search.'%'])->get();
```

`paginate()` works, with one limit set by Salesforce: a query cannot skip more than 2,000 records, so the total is capped at 2,000.

## Relationships

Define relationships the way you normally would:

```php
// Contact.php
public function account()
{
    return $this->belongsTo(Account::class);
}
```

```php
$contact = Contact::where('Email', 'some@email.com')->first();
$account = $contact->account;
```

And the reverse:

```php
// Account.php
public function contacts()
{
    return $this->hasMany(Contact::class);
}
```

```php
$contacts = $account->contacts;

$accounts = Account::with('contacts')->get();
```

The foreign key defaults to the name Salesforce uses for a standard lookup: `AccountId` in the examples above. Pass the field name as the second argument for custom lookups:

```php
return $this->hasMany(TouchPoint::class, 'Contact__c');
```

You can also select child records in the same query with a join, which Salesforce calls a sub-select:

```php
$account = Account::join('Contact', 'AccountId')->first();

$contacts = $account->Contacts['records'];
```

The child records come back as arrays under the relationship name, not as models.

## ReadOnly Fields

Fields listed in `$readonly` are never sent in insert or update requests. Use it for formula fields and other fields you select but cannot write:

```php
protected $readonly = [
    'Name',
];
```

## Picklists

Get the values of a picklist field, keyed by value with the label as the item:

```php
$statuses = $lead->getPicklistValues('Status');

// The same, without a model:
$statuses = SObjects::getPicklistValues('Lead', 'Status');
```

## Request Headers

To send extra headers with insert and update requests, for example to skip assignment rules, set `$custom_headers` on the model:

```php
$lead->custom_headers = ['Sforce-Auto-Assign' => 'FALSE'];
$lead->save();
```

## Local Sync

The `SyncsWithSalesforce` trait keeps a model in your own database in sync with a Salesforce object. You work with a local table, and the mapped fields are pushed to Salesforce whenever the model is created or updated.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SETechSolutions\EloquentSalesForce\Traits\SyncsWithSalesforce;

class SalesLead extends Model
{
    use SyncsWithSalesforce;

    // Required unless the class name matches the Salesforce object name.
    protected $salesForceObject = 'Lead';

    protected $fillable = [
        'email',
        'firstName',
        'lastName',
        'company',
    ];

    // Salesforce field => local attribute
    protected $salesForceFieldMap = [
        'Email' => 'email',
        'FirstName' => 'firstName',
        'LastName' => 'lastName',
        'Company' => 'company',
    ];
}
```

The table needs a nullable string column for the Salesforce record ID. It is called `salesforce` by default; set `$salesForceIdField` to use another name.

For anything more involved than a one-to-one mapping, map the Salesforce fields to accessors and mutators on your model:

```php
protected $salesForceFieldMap = [
    'Email' => 'email',
    'FirstName' => 'first_name',
    'LastName' => 'last_name',
    'Company' => 'company',
];

public function getFirstNameAttribute()
{
    return Str::before($this->name, ' ');
}

public function getLastNameAttribute()
{
    return Str::after($this->name, ' ');
}

public function setFirstNameAttribute($value)
{
    $nameParts = explode(' ', $this->attributes['name']);
    $nameParts[0] = $value;
    $this->attributes['name'] = implode(' ', $nameParts);
}

public function setLastNameAttribute($value)
{
    $nameParts = explode(' ', $this->attributes['name']);
    $nameParts[1] = $value;
    $this->attributes['name'] = implode(' ', $nameParts);
}
```

### Two-way sync

By default changes only flow from your database to Salesforce. To also pull changes made in Salesforce, enable it in the config file and list the models:

```php
'syncTwoWay' => true,
'syncPriority' => 'salesforce',
'syncTwoWayModels' => [
    App\Models\SalesLead::class,
],
```

`syncPriority` decides what happens when the local model has unsaved changes and Salesforce holds a newer version: `salesforce` (Salesforce wins), `local` (local wins), `silent` (skip the sync) or `exception` (throw).

Changes are pulled in two ways:

- `php artisan salesforce:sync` syncs every linked model. Schedule it as often as you need.
- `POST /api/syncObject/{id}` syncs the models linked to one Salesforce record. Call it from a Flow or an Apex trigger to sync a record as soon as it changes.

The endpoint requires a shared secret. Set `SF_SYNC_SECRET` in `.env` and send the same value in the `X-Salesforce-Sync-Secret` header of the callout. Requests without it are rejected.

## Custom Objects

For custom objects, or whenever the object name differs from the class name, set `$table`:

```php
<?php

namespace App\Models;

use SETechSolutions\EloquentSalesForce\Model;

class TouchPoint extends Model
{
    protected $table = 'TouchPoint__c';
}
```

# Bulk Operations

## Batch Queries

Salesforce limits the number of API calls you can make. To spend fewer of them, queue several queries and send them in one call.

Call `batch()` instead of `get()` to queue a query, then `SObjects::runBatch()` to run everything that was queued:

```php
Lead::select(['Id', 'FirstName', 'Company'])->limit(100)->batch();

Contact::select(['Id', 'FirstName', 'Phone'])->limit(50)->batch();

$batch = SObjects::runBatch();

$leads = $batch->results('Lead_0'); // get() also works
$contacts = $batch->results('Contact_1');
```

Each query is tagged with the class name of its model and its position in the batch. Pass your own tag when you batch several queries on the same model:

```php
Lead::select(['Id', 'FirstName', 'Company'])->limit(100)->batch(); // Lead_0

Lead::select(['Id', 'FirstName', 'Company'])->where('Company', 'Test')->batch('test_company');

$batch = SObjects::runBatch();

$leads = $batch->get('Lead_0');
$testCompanyLeads = $batch->get('test_company');
```

Up to 25 queries go into one API call; larger batches are split. A query that fails does not fail the batch: its result is the error response from Salesforce instead of a collection.

## Batch Collection Object

To build a batch of your own instead of using the shared one, create a `SOQLBatch`:

```php
use SETechSolutions\EloquentSalesForce\Database\SOQLBatch;

$batch = new SOQLBatch();

$batch->batch(Lead::where('FirstName', 'like', 'Test%'), 'test_leads');
$batch->query(Lead::where('Company', 'Acme'), Contact::where('LastName', 'Smith'));

$batch->run();
```

`SObjects::getBatch()` returns the shared batch.

## Bulk Insert

```php
$leads = collect([
    new Lead(['LastName' => 'One', 'Company' => 'Acme']),
    new Lead(['LastName' => 'Two', 'Company' => 'Acme']),
]);

$inserted = Lead::insert($leads);
```

Arrays of attributes work too. The inserted models are returned with their new IDs. Records are sent 200 at a time, the most Salesforce accepts per call.

## Bulk Update

To update every record matching a query:

```php
Account::where('Industry', 'Banks')->update(['Industry' => 'Banking']);
```

To update a collection of models with different values, change them and pass them to the facade. Only the fields that changed are sent, and the collection may mix object types:

```php
$accounts = Account::where('Industry', 'Banking')->get();

$accounts->each(function ($account) {
    $account->Rating = 'Hot';
});

$results = SObjects::update($accounts);
```

Salesforce handles each record on its own, so one failure does not stop the others. `$results` holds the outcome per record. Pass `true` as the second argument to make it all or nothing:

```php
SObjects::update($accounts, true);
```

Model events are not fired for bulk operations.

## Bulk Delete

```php
Lead::where('Email', 'like', '%@test.com')->delete();

// Every record of the object:
Lead::truncate();
```

# The SObjects Facade

The `SObjects` facade holds everything that is not tied to one model, and forwards any other call to the REST API. `SObjects::query()`, `SObjects::search()`, `SObjects::limits()`, `SObjects::sobjects()` and the other resources of [omniphx/forrest](https://github.com/omniphx/forrest) are all available.

## Authentication

`SObjects::authenticate()` makes sure a token exists and returns the token data. You do not need to call it yourself: requests authenticate when needed.

With the WebServer flow, send the user to `/login/salesforce`. After they approve access, Salesforce returns them to `/login/salesforce/callback`, which stores the token and redirects to `eloquent_sf.routes.login.redirectTo`.

## Anonymous Objects

To work with a record without writing a model for its object, use `SalesForceObject`:

```php
use SETechSolutions\EloquentSalesForce\SalesForceObject;

$leads = SalesForceObject::select('Email', 'Id')->from('Lead')->get();
```

Or turn the records of a raw query into models:

```php
$result = SObjects::query("SELECT Id, FirstName FROM Lead WHERE Email LIKE 'test@%'");

$leads = collect($result['records'])->map(fn ($record) => SObjects::object($record));
```

## IDs

`SObjects::convert()` turns a 15-character ID into its 18-character form:

```php
SObjects::convert('5003000000D8cuI'); // 5003000000D8cuIAAR
```

## Logging

Queries and write operations are logged at the `info` level. Choose the log channel with `SOQL_LOG=` in your `.env` file, or set it to `false` to turn the logging off.

Queries also dispatch Laravel's `QueryExecuted` event, so they appear in `DB::listen()`, Telescope and Debugbar. `SObjects::queryHistory()` returns everything that was sent during the current request.

## Errors

A failed request throws `SETechSolutions\EloquentSalesForce\Exceptions\RestAPIException`. Its `errorCode` and `fields` properties hold what Salesforce reported. Three errors have an exception class of their own, each extending `RestAPIException`:

- `MalformedQueryException`
- `RequestLimitExceeded`
- `UnableToLockRowException`

# Testing

## Testing your application

`SObjects::fake()` replaces Salesforce with an in-memory fake for the rest of the test. No request leaves your application.

```php
use SETechSolutions\EloquentSalesForce\Facades\SObjects;

public function test_a_lead_can_be_qualified(): void
{
    $salesforce = SObjects::fake([
        'Lead' => [
            ['Id' => '00Q000000000001AAA', 'Company' => 'Acme', 'Status' => 'Open'],
        ],
    ]);

    $this->post('/leads/00Q000000000001AAA/qualify')->assertOk();

    $salesforce->assertUpdated('Lead', '00Q000000000001AAA', ['Status' => 'Qualified']);
}
```

Queries return the records you stub for their object. The fake does not evaluate `WHERE` clauses, so every query on an object returns all records stubbed for it. When a test needs different answers for different queries, stub them by statement:

```php
$salesforce->records('Lead', [...]);

// A wildcard pattern, compared case-insensitively...
$salesforce->whenQuery("* from Lead where Email = 'none@example.com'*", []);

// ...or a closure. The response can also be an exception to throw.
$salesforce->whenQuery(
    fn (string $soql) => str_contains($soql, 'FOR UPDATE'),
    new MalformedQueryException('unexpected token'),
);
```

Inserts return generated IDs. Everything that is sent is recorded:

| Method | Asserts that |
|---|---|
| `assertQueried($statement)` | a statement was queried (exact, wildcard or closure) |
| `assertNotQueried($statement)` | a statement was not queried |
| `assertQueryCount($count)` | this many statements were queried |
| `assertCreated($object, $attributes)` | a record was inserted with these attributes |
| `assertNotCreated($object)` | no record of the object was inserted |
| `assertUpdated($object, $id, $attributes)` | a record was updated with these attributes |
| `assertDeleted($id)` | a record was deleted |
| `assertNothingWritten()` | nothing was inserted, updated or deleted |
| `assertNothingSent()` | no request was made at all |

`$attributes` can be an array of the values that must be present, or a closure that receives everything that was sent. `queries()` and `recorded()` return the raw log.

Further stubs: `describes($object, $description)` for describe calls and picklists, `layout($object, $fields)` for models without `$columns`, and `resource($method, $response)` for any other REST resource.

## Testing the package

See the [contribution guide](https://github.com/SETechSolutions/eloquent-sales-force/blob/main/CONTRIBUTING.md). The package's own tests run offline.

# Security

Please report vulnerabilities privately through the repository's [Security tab](https://github.com/SETechSolutions/eloquent-sales-force/security/advisories/new) instead of the issue tracker.

# Credits

- [Rob Lester](https://github.com/roblesterjr04), who created the package and maintained it through version 2.16
- [Everyone who contributed to the original project](https://github.com/roblesterjr04/EloquentSalesForce/graphs/contributors)
- [omniphx/forrest](https://github.com/omniphx/forrest)
