# Contributing

Thanks for helping out. Bug reports, documentation fixes and pull requests are all welcome.

## Reporting a bug

Open an issue with:

- the package, Laravel and PHP versions
- the code that triggers the problem
- the SOQL that was generated, if a query is involved (`->toSql()` returns it)
- what you expected and what happened instead

Please report security problems privately. See [SECURITY.md](SECURITY.md).

## Working on the code

```bash
git clone https://github.com/SETechSolutions/eloquent-sales-force.git
cd eloquent-sales-force
composer install
```

Before opening a pull request, run:

```bash
composer test      # the test suite
composer lint      # code style check (composer format fixes it)
composer analyse   # static analysis
```

The test suite runs against an in-memory fake, so no Salesforce org or credentials are needed. It needs the `mbstring` and `pdo_sqlite` PHP extensions.

### What a good pull request looks like

- One change per pull request.
- A test that fails without your change. For anything that affects generated queries, add a case to `tests/Unit/SoqlCompilationTest.php`.
- An entry under "Unreleased" in [CHANGELOG.md](CHANGELOG.md).
- Public behaviour stays backwards compatible within a major version. If it cannot, say so in the description.

### Testing against a real org

`tests/Live` holds a suite that talks to Salesforce. It is not part of the default run, and it **creates and deletes Lead and Task records**, so only ever point it at a Developer Edition org or a sandbox.

The org needs two custom fields on Lead: `Custom_Text_Field__c` (Text) and `Custom_Date_Field__c` (Date).

```bash
export SF_CONSUMER_KEY=...
export SF_CONSUMER_SECRET=...
export SF_USERNAME=...
export SF_PASSWORD=...          # password followed by the security token
export SF_LOGIN_URL=https://login.salesforce.com

vendor/bin/phpunit --testsuite Live
```

## Code of conduct

Be kind and assume good intent. Harassment of any kind is not tolerated.
