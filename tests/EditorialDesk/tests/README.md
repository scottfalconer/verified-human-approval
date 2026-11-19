# What the Editorial Desk tests cover

`php tests/Package/check-editorial-consumer.php`, from the repository root, runs this suite in a fresh copy of the application; in a copy kept with `--keep`, `composer test` runs it again. The suite uses the installed package APIs and this application's own kernel, routes, accounts, entity, policies, actions and MCP tools. It loads no code from the demo, the publishing example or their tests.

Each test owns a disposable SQLite database and signing keys. Content assertions use a second connection so an uncommitted in-memory change cannot count as success. The software authenticator creates real ES256 signatures that pass through the production WebAuthn verifier. BrowserKit drives the server protocol; it runs no browser JavaScript and involves no physical person or device.

| Scenario | Transport or boundary |
| --- | --- |
| Publish, promote a saved forward draft, direct live edit, archive, republish and delete | Web forms and official MCP SDK |
| No content mutation or execution record before human approval | Every protected workflow step |
| Free forward-draft editing preserves the existing public revision and its badge | Web and MCP |
| Direct live edits preserve the separately saved working draft | Web and MCP |
| Evidence and independently verifiable receipt survive deletion | Both workflows |
| Changed draft and newer approved live revision invalidate an older ceremony | Web and MCP |
| Revoked original agent grant or lost editor role blocks execution | Ceremony in progress |
| Explicit publisher-role exemption is recorded without a human-approval badge | Web and MCP |
| Reader cannot write or open another account's approval | Web and MCP |
| Completed request retries for all four operations and ceremony replay execute once | MCP plus browser approval |
| Direct ORM edit, unloaded-reference deletion and earlier lifecycle callback cannot bypass approval | Application entity manager |
| Corrupt approval evidence removes the badge while the article remains available | Public Symfony page |
| Missing, wrong-account, expired and reused enrollment grants; invalid CSRF, ceremony and origin | Host enrollment routes |
| Enrollment-anchor storage failure rolls back both the credential and trusted grant | Fresh SQLite transaction fault |
| Expired JSON ceremonies signal authentication; normal login returns to the existing pending request | Host Security entry point |

The suite runs on SQLite only; the packages' storage tests on PostgreSQL, MySQL and MariaDB are in the repository's own suite. This application's bearer tokens are its own, not OAuth, so these tests say nothing about OAuth clients.

The role-loss scenario verifies both boundaries: Symfony ends this application's browser session when the account's roles change, and the action's final authority check independently refuses the existing ceremony. The reader-write scenario creates only a genuine CSRF token in the authenticated test session, allowing the forged POST to reach the permission check despite the UI correctly hiding writing controls.

A retry with a request key prepares the action again, even after it completed, and compares the new target and effect with the stored proposal; if either differs, the retry is refused. So an action that can still be prepared after it ran keeps what its own execution changes out of its effect. Publication and live edits keep the content and status in `effect`: the revision they start from is in the preconditions, and the one they move to is derived from it. A retry after they complete returns the recorded outcome, unless a publication's working draft has been saved with different text since. Archive and delete refuse to prepare a page they have already archived or deleted, so a retry of either returns the recorded outcome too. Archive's effect also names the revision it moves to: once the page is published again, an old archive key does not match and is refused.

`testTrustedEnrollmentBindsTheAccountAndConsumesOnlyAValidCeremony` exercises grant ownership, expiration, reuse, CSRF, ceremony binding and WebAuthn validation. `testAnEnrollmentAnchorWriteFailureRollsBackTheCredentialAndTrustedGrant` injects an enrollment-audit storage failure after credential insertion, then verifies the unused grant and absent credential/audit before a successful retry. `testTheHostSignalsExpiredJsonCeremoniesAndReturnsLoginToTheExistingRequest` verifies the host-owned authentication response and same-request return path.

`browser_smoke.mjs` runs real production JavaScript with Chromium's virtual CTAP2 authenticator. It waits for rendered states after navigation, tests session loss before options and after the authenticator, retains the same request through login including a client-return fragment, and drops a successful completion response to prove that reloading discovers the existing committed result without replaying its POST. It does not test a physical passkey or Claude.
