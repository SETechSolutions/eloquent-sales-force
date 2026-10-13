# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

Releases up to 2.16.0 were published as [`rob-lester-jr04/eloquent-sales-force`](https://github.com/roblesterjr04/EloquentSalesForce/releases).

## [Unreleased]

## [3.0.1] - 2026-10-14

The package code is identical to 3.0.0; only the CI setup changed.

### Changed

- CI runs on Ubuntu 26.04 with `actions/checkout` 7 and `ramsey/composer-install` 4, so no action runs on the deprecated Node.js 20 any more.
- Dependabot groups GitHub Actions updates into a single pull request.

## [3.0.0] - 2026-10-14

First release of the maintained continuation. See [UPGRADE.md](UPGRADE.md) for upgrade steps.

### Added

- Laravel 12 and 13 support.
- `SObjects::fake()`: an in-memory Salesforce for tests, with `records()`, `whenQuery()`, `assertQueried()`, `assertCreated()`, `assertUpdated()`, `assertDeleted()` and more.
- `Model::where(...)->update([...])`, through the sObject collections API.
- `count()`, `exists()`, `doesntExist()`, `whereBetween()`, `whereNotBetween()`, `whereYear()`, `whereMonth()` and `whereDay()` compile to valid SOQL.
- Date fields can be declared with `$casts`; date-only fields are written and compared as `YYYY-MM-DD`.
- Credentials can be configured with `SF_*` environment variables alone.
- Queries dispatch Laravel's `QueryExecuted` event.
- `insert()` accepts plain arrays and returns the inserted models with their IDs.
- Salesforce exceptions carry the `errorCode` and `fields` of the error.
- An offline test suite, static analysis and a CI matrix covering PHP 8.2 to 8.5.

### Changed

- The namespace is `SETechSolutions\EloquentSalesForce`. `Lester\EloquentSalesForce` class names are aliased.
- Requires PHP 8.2+, Laravel 12+ and `omniphx/forrest` 3.
- `db:sync` is now `salesforce:sync`; the old name is kept as an alias.
- The OAuth login routes are only registered for the WebServer flow, and all routes can be cached.
- `MalformedQueryException`, `RequestLimitExceeded` and `UnableToLockRowException` extend `RestAPIException`.
- The default foreign key of `hasMany` and `hasOne` is cased the Salesforce way (`AccountId`).
- The access token is stored for an hour by default.

### Fixed

- 15 to 18 character ID conversion returned a wrong ID when the ID contained an uppercase `A` or `T`.
- DateTime values in queries were not converted to UTC.
- `update()` on a query or a relation failed with "Call to a member function prepare() on null". ([#102](https://github.com/roblesterjr04/EloquentSalesForce/issues/102))
- `CreatedDate`, `LastModifiedDate` and `IsDeleted` were selected on objects that do not have them. Thanks to Mathieu. ([#103](https://github.com/roblesterjr04/EloquentSalesForce/issues/103), [#119](https://github.com/roblesterjr04/EloquentSalesForce/pull/119))
- Batch results were not hydrated like query results, so they were always dirty. Thanks to Noé De Cuyper. ([#113](https://github.com/roblesterjr04/EloquentSalesForce/pull/113))
- The instance URL was kept in the session, which is not available in queue workers and console commands. It is now read from the stored token.
- `QueryExecuted` was never dispatched. ([#112](https://github.com/roblesterjr04/EloquentSalesForce/issues/112))
- The `*_FISCAL_*` date literals were never recognised because of invisible characters in the list.
- The API version list was cached forever.
- A new login was performed every 20 seconds because the token lifetime was read as seconds.
- The record ID and fields listed in `$readonly` were sent in insert and update requests.
- An exception without a previous exception raised a `TypeError` while being rethrown.
- Batch sub-requests were sent without the `v` version prefix when a version was configured.
- `select('*')` and `cursor()` produced invalid SOQL for models without `$columns`.
- `make:salesforce --pivot` referenced a stub that did not exist.
- `join()` used a guessed child relationship name, which is wrong for custom objects (`Children__r`). The name is now read from Salesforce, and can also be given directly: `join('Children__r', ...)` or `join('Child__c as Children__r', ...)`.
- Two-way sync read the Salesforce record without the mapped fields, so local values could be overwritten with null.
- With `syncPriority` set to `local`, Salesforce values still overwrote unsaved local changes.
- `syncDifferences()` compared the wrong keys.
- The test suite could not be run because of redeclared PHPUnit functions. ([#55](https://github.com/roblesterjr04/EloquentSalesForce/issues/55))

### Security

- **SOQL injection.** Values were inserted into executed queries without escaping. All values are now serialised as SOQL literals. Thanks to Casey Dwyer for the earlier report and patch. ([#107](https://github.com/roblesterjr04/EloquentSalesForce/pull/107))
- **SOQL injection through date literals.** A value starting with a date literal name was inserted into the query as it was. Date literals are now validated in full.
- **Unauthenticated sync endpoint.** `POST /api/syncObject/{id}` was registered in every application and open to anyone. It is now opt-in and requires a shared secret.
- **TLS verification.** Certificate verification is now enabled explicitly.
- Test model classes are no longer shipped and registered in production applications.
- Requires `omniphx/forrest` 3, which fixes an advisory in `firebase/php-jwt`.

### Removed

- `TestLead`, `TestTask`, `TestUser`, `TestModel` and `TestObserver` from `src/`.
- The unused `Exceptions\Handler` class.
- Support for PHP below 8.2 and Laravel below 12.

[Unreleased]: https://github.com/SETechSolutions/eloquent-sales-force/compare/v3.0.1...HEAD
[3.0.1]: https://github.com/SETechSolutions/eloquent-sales-force/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/SETechSolutions/eloquent-sales-force/releases/tag/v3.0.0
