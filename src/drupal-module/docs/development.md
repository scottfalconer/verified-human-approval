# Developing and testing the Drupal module

The Drupal tests use a separate Composer project with real Drupal core. The
monorepo's root `composer.json` replaces its library packages and installs the
Symfony demo; installing only that root project does not test a Drupal consumer.

This module requires the portable audit and credential repository contracts,
on `main` since [PR #3](https://github.com/scottfalconer/verified-human-approval/pull/3).
The local path repositories below consume this working tree; an older checkout
does not provide the required contracts.

## Requirements

Use PHP 8.3 or 8.4, Composer 2, and the PHP extensions required by Drupal 11 and
the module. The kernel tests need PDO SQLite backed by SQLite 3.45 or newer,
OpenSSL, sodium, and `zend.assertions=1`. Install the actual required runtime;
do not use `--ignore-platform-reqs` or a fictitious Composer platform version.

The initial adapter supports SQLite. Passing these tests does not establish
transaction coordination on MySQL or PostgreSQL. The independent consumer
defaults to the supported Drupal 11.4 release line. The first local run used
Drupal 11.4.12, PHP 8.4.24, and SQLite 3.46.1.

## Create a separate local consumer

Run these commands from the repository root. Choose a new directory outside the
repository for `VHA_DRUPAL_CONSUMER`.

```sh
export VHA_DRUPAL_CONSUMER="$(mktemp -d)/vha-drupal"
mkdir -p "$VHA_DRUPAL_CONSUMER"
php tests/Drupal/consumer-manifest.php > "$VHA_DRUPAL_CONSUMER/composer.json"
composer --working-dir="$VHA_DRUPAL_CONSUMER" validate --strict --no-check-publish
composer --working-dir="$VHA_DRUPAL_CONSUMER" install --no-interaction --prefer-dist
composer --working-dir="$VHA_DRUPAL_CONSUMER" check-platform-reqs
php tests/Drupal/assert-consumer.php "$VHA_DRUPAL_CONSUMER"
```

The generator adds path repositories for the three local packages and requests
`dev-main` explicitly for each. This is necessary while those packages are
unpublished. The WebAuthn package's `self.version` constraint resolves to the
same development version of core. Other dependencies keep stable resolution.

Composer installs the module at
`web/modules/contrib/verified_human_approval` as a symlink to
`src/drupal-module`. Edits to the module are visible to the consumer immediately.
The manifest uses Drupal's scaffold and Composer installer plugins to provide a
real web root and core installation.

The check script reports the installed package/runtime versions and rejects a
consumer that has pulled in the Symfony framework/security/Twig bundles or MCP
packages. Drupal's own Symfony components remain normal dependencies.

To test a particular Drupal release, pass one constraint to the generator, for
example `php tests/Drupal/consumer-manifest.php '11.4.12'`. Use a fresh consumer
or run `composer update` deliberately after changing an existing manifest. Keep
the consumer's generated `composer.lock` with local verification records when
an exact dependency set matters.

## Run the kernel suite

```sh
VHA_DRUPAL_ROOT="$VHA_DRUPAL_CONSUMER/web" \
  php -d zend.assertions=1 "$VHA_DRUPAL_CONSUMER/vendor/bin/phpunit" \
  -c tests/Drupal/phpunit.xml.dist
```

The bootstrap loads Drupal's own test bootstrap from the consumer. Tests live
in `src/drupal-module/tests/src` and use the normal
`Drupal\Tests\verified_human_approval` namespace. Drupal discovers them through
the installed module. The module's tests carry their own copy of the
repository's synthetic WebAuthn authenticator (`tests/src/Support`); the suite
does not load the Symfony demo test bootstrap.

The configuration defaults to `sqlite://localhost/:memory:`. Kernel tests
install the schemas they need and use isolated table prefixes. An individual
test that needs two database connections must create its own temporary SQLite
file; separate in-memory connections do not share a database.

Use PHPUnit's normal `--filter` or a test-file argument to run a focused check.
The browser's passkey prompt is not exercised by PHP kernel tests. The synthetic
authenticator makes real ES256 signatures so the production WebAuthn validator
can be tested, but it does not prove a person touched a physical authenticator.

## CI

`.github/workflows/drupal.yml` builds a fresh independent consumer under the
runner's temporary directory on PHP 8.3 and 8.4. It verifies actual platform
requirements, checks the package boundary, and runs the Drupal kernel suite.
The root Symfony suite and the Drupal suite intentionally use their respective
dependency installations.
