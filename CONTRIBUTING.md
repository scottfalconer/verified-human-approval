# Contributing

Open issues and pull requests in [this repository](https://github.com/scottfalconer/verified-human-approval). The seven packages under `src/` are developed and released together here. Once the [split workflow](.github/workflows/split.yml) publishes each package to a repository of its own, that repository is a read-only copy. Report a suspected vulnerability privately, as [SECURITY.md](SECURITY.md) describes, not in an issue.

## Run the checks

You need PHP 8.3 or later with the extensions `composer.json` lists, and Composer. Node.js 22 or later runs the approval card's script tests, and Python 3 the HTTP checks.

```bash
composer install
composer test        # PHPUnit, two to three minutes
composer analyse     # PHPStan at level 8
composer cs          # the coding standard; composer cs:fix applies it
node --test tests/card_lifecycle.mjs tests/approval_recovery.mjs
PHP_BIN=php python3 tests/http_smoke.py
PHP_BIN=php python3 tests/oauth_http_smoke.py
```

Without database servers, the PostgreSQL and MySQL tests skip; [Verification](README.md#verification) shows how to run them. A change to what a package requires or ships should also pass the package checks that CI runs, which install the packages into new Composer projects:

```bash
php tests/Package/check-consumer.php archives
php tests/Package/check-symfony-consumer.php
php tests/Package/check-editorial-consumer.php
```

## Pull requests

- Keep a pull request to one change, with tests where behavior changes. Follow the tests in `tests/`: they read back what was stored and what a person or an agent is shown.
- Concrete classes in `src/` are `final`, and every PHP file declares `strict_types`. `composer cs` and `composer analyse` must pass.
- Update the documentation the change affects, and add a line under "Unreleased" in [CHANGELOG.md](CHANGELOG.md) for anything a user of the packages would notice.
- CI ([tests.yml](.github/workflows/tests.yml)) runs all of the above on every push and pull request, and must pass.
