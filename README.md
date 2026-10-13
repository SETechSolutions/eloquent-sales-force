<p align="center">
  <img src="docs/_media/banner-gray.png" alt="Eloquent Salesforce"><br>
</p>

[![Tests](https://github.com/SETechSolutions/eloquent-sales-force/actions/workflows/tests.yml/badge.svg)](https://github.com/SETechSolutions/eloquent-sales-force/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/setechsolutions/eloquent-sales-force.svg)](https://packagist.org/packages/setechsolutions/eloquent-sales-force)
[![Total Downloads](https://img.shields.io/packagist/dt/setechsolutions/eloquent-sales-force.svg)](https://packagist.org/packages/setechsolutions/eloquent-sales-force)
[![License](https://img.shields.io/packagist/l/setechsolutions/eloquent-sales-force.svg)](LICENSE)

# Eloquent Salesforce

Work with Salesforce objects the way you work with Eloquent models: query, create, update, delete and relate them with the API you already know. The package translates Eloquent queries to SOQL and talks to the Salesforce REST API for you.

```php
$leads = Lead::where('Company', 'Acme')->orderBy('LastName')->limit(20)->get();

$lead = Lead::create(['LastName' => 'Lovelace', 'Company' => 'Analytical Engines']);

$lead->update(['Status' => 'Working - Contacted']);

$account->contacts()->where('Email', 'like', '%@example.com')->get();
```

This is the maintained continuation of [roblesterjr04/EloquentSalesForce](https://github.com/roblesterjr04/EloquentSalesForce) by Rob Lester, which stopped at Laravel 11. Version 3 supports Laravel 12 and 13 and can replace `rob-lester-jr04/eloquent-sales-force` without code changes: the original class names keep working.

## Requirements

| Package version | Laravel | PHP |
|---|---|---|
| 3.x | 12, 13 | 8.2+ (8.3+ for Laravel 13) |

For Laravel 11 and older, use the original package.

## Installation

```bash
composer require setechsolutions/eloquent-sales-force
```

> A fresh Laravel 13 application ships with Guzzle 8, while the Salesforce client this package builds on still needs Guzzle 7. If Composer reports a conflict on `guzzlehttp/guzzle`, add `-W` so it may switch to Guzzle 7, which Laravel 13 supports as well:
>
> ```bash
> composer require setechsolutions/eloquent-sales-force -W
> ```

Add your connected app's credentials to `.env`:

```dotenv
SF_CONSUMER_KEY=
SF_CONSUMER_SECRET=
SF_USERNAME=
SF_PASSWORD=
SF_LOGIN_URL=https://login.salesforce.com
```

Then create a model:

```bash
php artisan make:salesforce Lead
```

```php
namespace App\Models;

use SETechSolutions\EloquentSalesForce\Model;

class Lead extends Model
{
    public $columns = ['Id', 'FirstName', 'LastName', 'Email', 'Company'];
}
```

## Coming from the original package

1. Replace the dependency:

   ```bash
   composer remove rob-lester-jr04/eloquent-sales-force
   composer require setechsolutions/eloquent-sales-force
   ```

2. That's it for most applications. `Lester\EloquentSalesForce\*` class names, the `eloquent_sf` config file, the `SObjects` facade and a `soql` connection in `config/database.php` all keep working.

3. Read [UPGRADE.md](UPGRADE.md) for the handful of behaviour changes, most of them security fixes.

## Documentation

The full guide is at **[setechsolutions.github.io/eloquent-sales-force](https://setechsolutions.github.io/eloquent-sales-force/)**, and in [docs/README.md](docs/README.md).

## Testing your application

Swap Salesforce for an in-memory fake, stub what queries return, and assert on what was written:

```php
use SETechSolutions\EloquentSalesForce\Facades\SObjects;

$salesforce = SObjects::fake([
    'Lead' => [['Id' => '00Q000000000001AAA', 'Company' => 'Acme']],
]);

$this->post('/leads/00Q000000000001AAA/qualify');

$salesforce->assertUpdated('Lead', '00Q000000000001AAA', ['Status' => 'Qualified']);
```

## Contributing

Bug reports and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). The test suite runs offline, so you do not need a Salesforce org to contribute.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## Credits

- [Rob Lester](https://github.com/roblesterjr04), who created the package and maintained it through version 2.16
- [Everyone who contributed to the original project](https://github.com/roblesterjr04/EloquentSalesForce/graphs/contributors)
- [omniphx/forrest](https://github.com/omniphx/forrest), the Salesforce REST client underneath

## License

MIT. See [LICENSE](LICENSE).
