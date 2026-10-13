# Upgrading

## From `rob-lester-jr04/eloquent-sales-force` 2.x to 3.0

### Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- `omniphx/forrest` 3 (installed for you)

### Swap the package

```bash
composer remove rob-lester-jr04/eloquent-sales-force
composer require setechsolutions/eloquent-sales-force
```

The two packages cannot be installed side by side.

### Namespace

The classes now live in `SETechSolutions\EloquentSalesForce`. The old `Lester\EloquentSalesForce` names are aliased and keep working, so nothing has to change right away. To move over at your own pace, replace the namespace in your `use` statements:

```diff
-use Lester\EloquentSalesForce\Model;
+use SETechSolutions\EloquentSalesForce\Model;
```

`Observers\Syncronizer` was renamed to `Observers\Synchronizer`; the old name is aliased too.

If you registered `Lester\EloquentSalesForce\ServiceProvider` by hand in `bootstrap/providers.php` or `config/app.php`, remove that line. The provider is discovered automatically, and listing it under its old name as well would register it twice.

### Changes that can affect you

**Values are escaped.** Every value is now serialised into a SOQL literal before a query is sent. Previously values were inserted as they were, which allowed SOQL injection. If you escaped quotes yourself before passing a value to `where()`, stop doing so, or the backslashes will be escaped a second time.

**Date fields only accept dates.** A value compared against a date field must be a `DateTimeInterface`, an ISO 8601 date or dateTime string, or a SOQL date literal such as `LAST_N_DAYS:7`. Anything else throws an `InvalidArgumentException` instead of being sent to Salesforce.

**DateTime values are converted to UTC.** `where('CreatedDate', '>', $date)` used to format the date in its own timezone and then mark it as UTC. If your application's timezone is not UTC, queries now return the records you actually asked for.

**The sync webhook needs a secret.** `POST /api/syncObject/{id}` was open to anyone. It is now only registered when `syncTwoWay` is enabled, and rejects requests that do not send the value of `SF_SYNC_SECRET` in the `X-Salesforce-Sync-Secret` header. Set the secret in `.env` and add the header to the callout in Salesforce.

**The login routes are only registered for the WebServer flow.** `/login/salesforce` and its callback no longer exist in applications using another authentication method. Set `eloquent_sf.routes.login.enabled` to `true` to force them on.

**Test classes are gone from the package.** `TestLead`, `TestTask`, `TestUser`, `TestModel` and `TestObserver` were shipped in `src/` by accident and have been removed.

**`db:sync` is now `salesforce:sync`.** The old name still works.

**The token is kept for an hour.** `forrest.storage.expire_in` is in seconds. The old default of `20` made the package log in again every 20 seconds. If you published the config file, change it to `3600`.

**TLS certificates are verified.** If you published the config file, add this to the `forrest` array, as Forrest itself defaults to not verifying:

```php
'client' => [
    'http_errors' => true,
    'verify' => true,
],
```

**The default foreign key of `hasMany` and `hasOne` is `AccountId`.** It used to be `accountId`, which did not match the field names Salesforce returns, so eager loading found no related records. If you passed the foreign key explicitly, nothing changes.

**Specific exceptions extend `RestAPIException`.** `MalformedQueryException`, `RequestLimitExceeded` and `UnableToLockRowException` can now be caught together, and carry the Salesforce `errorCode` and `fields`.

**`SyncsWithSalesforce` no longer overrides `boot()`.** If your model defined its own `boot()` and worked around the trait's, you can remove the workaround.

### New things you may want to use

- Credentials can be set with `SF_*` environment variables alone; the `soql` entry in `config/database.php` is optional.
- Date fields can be declared with `$casts` (`'date'`, `'datetime'`) as well as `$dates`. Fields listed in `$shortDates`, or cast to `date`, are written and compared as `YYYY-MM-DD`.
- `Model::where(...)->update([...])`, `count()`, `exists()`, `whereBetween()`, `whereYear()`, `whereMonth()` and `whereDay()` work.
- `SObjects::fake()` lets you test code that uses Salesforce models without a Salesforce org.
- Every query dispatches Laravel's `QueryExecuted` event, so it shows up in `DB::listen()`, Telescope and Debugbar.
