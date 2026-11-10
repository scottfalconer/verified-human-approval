# Independent application acceptance

Run `composer test` from this application's root. The suite uses the installed package APIs and this application's own kernel, routes, accounts, entity, policies, actions and MCP tools. It does not load the upstream example or its tests.

Each test owns a disposable SQLite database and signing keys. Content assertions use a second connection so an uncommitted in-memory change cannot count as success. The software authenticator creates real ES256 signatures that pass through the bundle's production WebAuthn verifier. BrowserKit drives the server protocol; it does not execute browser JavaScript or attest a physical person/device.

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

The default runtime uses SQLite. The bundle's own PostgreSQL/MySQL contract suite remains separate; these tests do not claim a server-database deployment test. The example's local bearer-token implementation is host-owned and is not an OAuth interoperability test.

The role-loss scenario verifies both boundaries: Symfony ends this application's browser session when the account's roles change, and the action's final authority check independently refuses the existing ceremony. The reader-write scenario creates only a genuine CSRF token in the authenticated test session, allowing the forged POST to reach the permission check despite the UI correctly hiding writing controls.

The first run exposed a completed-retry integration constraint: the bundle re-prepares an action and compares its target/effect even after it completed. A computed `currentRevision + 1` in the effect consequently changes because of the successful execution itself. Publication and live-edit actions keep semantic content/status in `effect` and the exact revision transition in signed preconditions/display. Archive/delete actions refuse terminal preparation, so their recorded completed result remains reusable; a later distinct operation still conflicts with an old key.

`testTrustedEnrollmentBindsTheAccountAndConsumesOnlyAValidCeremony` exercises grant ownership, expiration, reuse, CSRF, ceremony binding and WebAuthn validation. `testAnEnrollmentAnchorWriteFailureRollsBackTheCredentialAndTrustedGrant` injects an enrollment-audit storage failure after credential insertion, then verifies the unused grant and absent credential/audit before a successful retry. `testTheHostSignalsExpiredJsonCeremoniesAndReturnsLoginToTheExistingRequest` verifies the host-owned authentication response and same-request return path.

`browser_smoke.mjs` runs real production JavaScript with Chrome's virtual CTAP2 authenticator. It waits for rendered states after navigation, tests session loss before options and after the authenticator, retains the same request through login including a client-return fragment, and drops a successful completion response to prove that reloading discovers the existing committed result without replaying its POST. It does not test a physical passkey or Claude.
