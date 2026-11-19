# Editorial Desk

An independent Symfony application that the tests use to check the Verified Human Approval packages from the outside. It is a small publishing desk. Editors save drafts freely; publishing, editing a live page, archiving and deleting each wait for the editor's passkey, whether the editor asks in the browser or through an agent over MCP. Its publisher role is exempt, and those changes are recorded as made without a passkey.

It has its own `App` namespace, accounts, form login, bearer tokens for agents, workflow, entity and MCP tools, and it stores approvals with the bundle's Doctrine storage on SQLite. It loads no code from the demo or the publishing example: it installs the packages with Composer, as any other project would. It was first written against the packages at commit `c6a99c30`, which its `composer.json` records. Like the rest of this repository, it is MIT licensed.

## Run it

From the repository root:

```sh
php tests/Package/check-editorial-consumer.php
php tests/Package/check-editorial-consumer.php --browser
```

The runner copies this application into a new temporary project and installs all seven current packages from `src/` through Composer path repositories with `symlink: false`. The application then runs on its own Composer autoloader and development dependencies. Nothing is carried over: no package snapshot, root replacement package, database, enrollment link, signing key or passkey. The tests create fresh state and choose random loopback ports. `--keep` keeps the generated project for diagnosis; keep its generated credentials private.

The default run executes the application's PHPUnit suite, then real HTTP and MCP checks over TCP. `--browser` also installs the pinned Playwright and runs the bundle's production JavaScript in Chrome with a virtual CTAP2 authenticator. Set `PLAYWRIGHT_CHROMIUM_EXECUTABLE` to an installed Chrome, or run `npx playwright install --with-deps chromium` in the generated project first. CI runs the check with `--browser` in its `editorial-consumer` job. None of this shows that a physical passkey prompt, or Claude, works with the application.

[The scenarios](tests/README.md) lists what the suite covers.

## What to copy from it

`src/Account/PasskeyEnrollment.php`, `src/Controller/AccountController.php` and `templates/account/passkeys.html.twig` are a complete trusted-enrollment flow. An operator issues a link that works once. It is bound to the signed-in browser account, its session, a CSRF token and a short ceremony, and using the link and anchoring the new passkey commit in one database transaction. Operator-issued links are this application's policy, not the only way to enroll or recover a passkey.

The host owns login, and the bundle does not implement it. When a passkey request (a JSON `POST` to the `options` or `complete` route of an approval or of the passkey page) arrives without a session, `src/Security/BrowserEntryPoint.php` answers HTTP 401 with `{"status":"authentication_required"}`, and the bundle's page script asks the person to sign in and check the page again. Any other request goes to the login form and returns to the same page after sign-in.

This is a test application, not a site to deploy: `public/index.php` starts in the `dev` environment unless `APP_ENV` is set.
