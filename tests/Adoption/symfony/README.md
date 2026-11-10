# Independent Symfony consumer check

Run `php tests/Package/check-symfony-consumer.php` from the checkout. Composer and network access (or a populated Composer cache), PHP 8.3+, PDO SQLite, Sodium, OpenSSL, mbstring and DOM are required. `--keep` retains only the newly created temporary application for investigation; its generated credentials remain local.

The check copies the public Doctrine publishing example into a fresh application's `App` namespace, installs all seven actual packages with Composer path repositories and `symlink: false`, and uses only that application's autoloader. It does not load the monorepo's replacements, tests, demo, state or credentials.

The installed testing package drives passkey registration and approval over the actual rendered panel, real WebAuthn signatures, Symfony Security form login, the application's personal bearer-token mapping and the official SDK through its HTTP MCP controller. A separate SQLite connection checks what committed. It covers protected writes through web and MCP, no publication before approval, another account's refusal, changed content, revoked delegation, idempotent retry and independent receipt verification.

This proves example-derived package adoption in a fresh Symfony application. It does not claim a published Packagist installation, independent human usability evaluation, physical-authenticator or JavaScript-browser validation, or an OAuth issuer implementation. The application owns its Security account/token mapping; the bundle owns approval.
