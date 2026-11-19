# Independent Symfony consumer check

Run `php tests/Package/check-symfony-consumer.php` from the checkout. Composer and network access (or a populated Composer cache), PHP 8.3+, PDO SQLite, Sodium, OpenSSL, mbstring and DOM are required. `--keep` retains only the newly created temporary application for investigation; its generated credentials remain local.

The check copies the public Doctrine publishing example into a fresh application's `App` namespace, installs all seven actual packages with Composer path repositories and `symlink: false`, and uses only that application's autoloader. It does not load the monorepo's replacements, tests, demo, state or credentials.

The installed testing package drives passkey registration and approval over the actual rendered panel, real WebAuthn signatures, Symfony Security form login, the application's personal bearer-token mapping and the official SDK through its HTTP MCP controller. A separate SQLite connection checks what committed. It covers protected writes through web and MCP, no publication before approval, another account's refusal, changed content, revoked delegation, idempotent retry and independent receipt verification.

This shows the packages working, installed with Composer, in a separate Symfony application. That application is the publishing example's code, not one written from the documentation alone, and the packages come from this checkout, not from Packagist. The passkey is a software one, no browser JavaScript runs, and the agent tokens are the application's own bearer tokens, not OAuth. The application maps its accounts and tokens to actors with Symfony Security; the bundle handles approval.
