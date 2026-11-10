# Editorial Desk acceptance consumer

This is the independent application supplied for the integration review, retained as a private acceptance fixture. It has its own `App` namespace, accounts, form login, bearer authentication, workflow, entity and MCP tools. It does not load the monorepo demo or the publishing example. The supplied application declares its license **proprietary**; retaining this fixture does not relicense it under the repository's MIT license. Its original package baseline was `c6a99c3064adc2531559968fe8ace9d3c40c5502`.

Run from the repository root:

```sh
php tests/Package/check-editorial-consumer.php
php tests/Package/check-editorial-consumer.php --browser
```

The runner copies this fixture into a new temporary project and installs all seven current packages using Composer path repositories with `symlink: false`. It uses the consumer's own Composer autoloader and development dependencies. No bundled package snapshots, root replacement packages, existing application database, enrollment grant, signing key or credential is used. Tests generate fresh state and choose random loopback ports. `--keep` retains the generated project for diagnosis; keep its generated credentials private.

The normal run executes the independent PHP application suite and real TCP HTTP checks. `--browser` additionally installs its pinned Playwright dependency and runs Chrome with a virtual CTAP2 authenticator. Set `PLAYWRIGHT_CHROMIUM_EXECUTABLE` to an installed Chrome executable or run `npx playwright install --with-deps chromium` first in the generated project. These checks do not prove physical passkey prompts or Claude compatibility.

Maintained changes from the supplied application are limited to current-package installation, repeatable asset setup, formatting, host-owned session-expiry signaling and regression tests. The browser harness now waits for rendered registration and completed-request states: reading a response body after the production script navigates was a test race. An explicit `401 {"status":"authentication_required"}` comes from this application's Security entry point, while ordinary browser navigation retains form login and its same-page target. The bundle does not implement the host's login.

`src/Account/PasskeyEnrollment.php`, `src/Controller/AccountController.php` and `templates/account/passkeys.html.twig` provide the complete trusted-enrollment reference: an operator-issued one-use grant is bound to a current browser account, session, CSRF token and short ceremony; consumption and credential anchoring share the database transaction. This fixture's operator-issued links are one host policy, not a universal enrollment or recovery policy. See [the acceptance scenarios](tests/README.md).
