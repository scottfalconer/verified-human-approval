# Test your application's integration

Test the protected action through its MCP endpoint and the review page through a separate signed-in browser. The browser completion should perform the mutation; the subsequent MCP status call should only read its result. Use the real action, authorizer, storage and WebAuthn verifier. This catches mistakes in the host's wiring that a mocked approval service would miss.

Start with the [first protected action](first-action.md), [host authentication](authentication.md) and [trusted enrollment](enrollment.md). The testing helpers create no identities, grants or enrollment authority.

## Prepare an isolated test environment

Configure the path repository from the [installation guide](index.md#1-install-from-a-checkout), then install the development helper and your normal test dependencies:

```bash
composer require --dev verified-human-approval/testing:dev-main
```

The helper does not depend on a test runner. A Symfony `WebTestCase` needs your application's PHPUnit/FrameworkBundle test setup. The MCP example below uses the SDK already installed with the MCP bundle, and `nyholm/psr7` from the installation guide.

Make the test environment explicit. Symfony's usual `.env.test` replaces `.env.local`, so settings present only in the latter can be missing when tests boot. For the worked example, set:

```dotenv
# .env.test: paths and origin for this test application only
APP_ENV=test
APP_ORIGIN=https://approval.test
APP_RP_ID=approval.test
APP_SQLITE_PATH=/absolute/path/to/your-app/var/test/application.sqlite
```

Generate a **separate** signing key for the test fixture using the worked example's `bin/create-approval-key.php`. Supply its `APPROVAL_RECEIPT_KEY_ID`, `APPROVAL_RECEIPT_SECRET_KEY`, `APPROVAL_RECEIPT_PUBLIC_KEY` and `MCP_REQUEST_STATE_KEY` through the test environment or ignored `.env.test.local`. Host applications may use different environment variable names; use the names referenced by your actual service configuration. Never load production credentials to make a test boot.

Keep the test's state independent:

- Use a new database or schema per test, including accounts, delegation grants, credential anchors, approval rows and the business entity. All approval-related writes still share one connection inside that test.
- Use a test-only cache and session directory. For parallel runs, partition the database, cache and sessions by worker. A rebooted kernel must reconnect to that test's same database so the browser and MCP client see the same request.
- Reset the approval limiter's backing cache between tests, or use a unique cache namespace per test. A fresh kernel does not reset a persistent limiter's budget. Keep the limiter enabled; set a deliberately small budget only in tests of rate limiting.
- Configure the test host as trusted and use the same origin for the browser, bearer-token destination, `public_origin`, WebAuthn configuration and `VirtualAuthenticator`. Do not change the origin to bypass a rejected signature.

The helper runs without a listening web server. It does not contact the hostname in these example URLs.

## Register through your account boundary

Your fixtures create a real local account, its permissions and an active MCP grant. In `WebTestCase`, `loginUser($account)` establishes the browser's host session; the MCP connection still needs its independently validated bearer token. For an enrollment test, complete your account process's trusted enrollment authorization before requesting its registration options.

```php
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

// $browser is a KernelBrowser authenticated as the fixture account.
// Your fixture has authorized this account to enroll its first passkey.
$passkey = new VirtualAuthenticator('https://approval.test');
$registered = PasskeyPanel::open($browser, 'https://approval.test/account/passkeys')
    ->register($passkey);
self::assertSame('registered', $registered->status, (string) $registered);
```

`/account/passkeys` is an example application route, not a bundle-provided route. This works when your page follows the [registration panel protocol](enrollment.md); otherwise call your own enrollment endpoints using `VirtualAuthenticator::registration($savedOptions)`. Keep the production verifier and the credential repository's anchor operation in place. Directly inserting an unanchored credential is not an equivalent fixture.

These signatures are cryptographically valid but synthetic. Their user-presence and user-verification flags are supplied by software. Keep this package in `require-dev` and its credentials confined to disposable test accounts.

## Connect an MCP client to the real kernel

Construct the SDK client and transport just as an external client would, substituting the in-process HTTP client:

```php
use App\Kernel;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use VerifiedHumanApproval\Testing\KernelHttpClient;

$factory = new Psr17Factory();
$agent = Client::builder()
    ->setClientInfo('approval-integration-test', '1.0')
    ->setMaxRetries(0)
    ->build();
$agent->connect(new HttpTransport(
    endpoint: 'https://approval.test/mcp',
    headers: ['Authorization' => 'Bearer '.$fixtureBearerToken],
    httpClient: new KernelHttpClient(
        static fn () => new Kernel('test', false),
        $factory,
        $factory,
    ),
    requestFactory: $factory,
    streamFactory: $factory,
));
```

`$fixtureBearerToken` must come from your host's authentication fixture, with the scopes and delegation record the authorizer reads. The client passes its headers through the real firewall; it neither establishes a Symfony user nor supplies a bearer token by itself. It creates a fresh kernel per request and captures JSON or streamed SSE responses. Disconnect the SDK client in `finally` or test teardown, and shut down the BrowserKit kernel before deleting that test's own state.

## Prove the browser performs the action

For the worked example's `publish_document` tool, create a draft fixture and call:

```php
$pending = $agent->callTool('publish_document', ['document_id' => $documentId]);
self::assertFalse($pending->isError);
self::assertSame('pending', $pending->structuredContent['status']);
$requestId = $pending->structuredContent['requestId'];
$reviewUrl = $pending->structuredContent['review_url'];

// Assert from the application's database: published_revision is still null,
// and mutation_count is zero before the browser approves.
$panel = PasskeyPanel::open($browser, $reviewUrl);
$answer = $panel->approve($passkey);
self::assertSame(200, $answer->httpStatus, (string) $answer);
self::assertSame('completed', $answer->status, (string) $answer);
self::assertNull(PasskeyPanel::find($browser));

// Assert the frozen title/body/revision were published and mutation_count is one.
$status = $agent->callTool('approval_status', ['request_id' => $requestId]);
self::assertSame('completed', $status->structuredContent['status']);
// Read status again and assert mutation_count remains one.
```

Adapt the business tool and result fields to your own implementation. Assert the actual business row; an HTTP 200 or `isError === false` alone does not prove publication. `PanelAnswer::refused()` tests the HTTP status; `status`, `reason` and `data` give the application's answer. A 200 response with `status: invalidated` means the action did not run.

After a JSON answer, `approve()`, `register()` and `cancel()` reload the original page so the browser is ready for rendered-state assertions. This extra GET also happens after a refusal; the production script may leave the current page in place. The helper does not run JavaScript or test the device prompt, CSS, browser origin enforcement or the return-to-agent behavior. Keep a real-browser passkey test for those.

## Change authority or data during the ceremony

Split the helper's two requests to test what happens while the person is looking at the passkey prompt:

```php
$panel = PasskeyPanel::open($browser, $reviewUrl);
$ceremony = $panel->begin();
self::assertFalse($ceremony->refused(), (string) $ceremony);

// Through your host fixture, edit this document and increment its revision.
$assertion = $passkey->assertion($ceremony->data['publicKey']);
$answer = $panel->complete($ceremony->data['ceremony_id'], $assertion);
self::assertSame('invalidated', $answer->status, (string) $answer);
self::assertSame('stale_resource', $answer->reason);
// Assert no published fields changed and mutation_count is still zero.
```

Repeat the scenario with current publish permission removed, the account disabled, or the original agent grant revoked. Check the outcome your authorizer specifies and zero business mutations. Also exercise cancellation, expired requests, replayed assertions and repeated completion. A retry or second status poll must not execute the action twice.

Test that an unsigned or invalid passkey response cannot complete an action, a colleague's account cannot read or approve this account's request, a signed-out browser goes through the host's login path, and missing/insufficient MCP authorization gets your host's challenge. Those checks belong to the application boundary as well as the core's unit tests.

## Verify the downloaded receipt

Download the completed request's receipt through the same signed-in browser. Preserve nested JSON objects when reading it:

```php
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

$receipt = CanonicalJson::map(CanonicalJson::decode($rawReceipt));
$keys = TrustedKeys::fromDocument($trustedKeyDocument);
$verified = (new ReceiptVerifier())->verify($receipt, $keys);
self::assertTrue($verified['applicationSignatureValid']);
self::assertSame(Receipt::COMMIT, $verified['receiptSignedAt']);
```

Do not read the receipt using `json_decode($rawReceipt, true)`: it loses the distinction between empty objects and empty lists, which changes the bytes covered by the signature. The separately trusted key document has this shape (replace the placeholder with your fixture's real public key):

```json
{
  "format": "verified-human-approval/receipt-keys/v2",
  "keys": [
    {
      "keyId": "application-test-1",
      "publicKey": "<base64url Ed25519 public key>",
      "validFrom": null,
      "validUntil": null
    }
  ]
}
```

`/approval/receipt-keys.json` publishes the application's public keys; assert they match the public key you configured for this fixture. A key taken from the evidence itself is not an independent trust source. `ReceiptVerifier` verifies the application's signed historical report; passkey evidence has its own verification described in the [evidence guide](https://github.com/scottfalconer/verified-human-approval/blob/main/docs/evidence.md).

The repository's [whole-application helper test](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Testing/DemoApplicationTest.php) is an executable reference covering enrollment, real SDK calls into the kernel, successful approval, stale drafts, cancellation, rate limiting, cross-device handoff and receipt verification. The [separate Composer consumer](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Package/check-consumer.php) also checks that these helpers work from the installed package without the monorepo autoloader or a PHPUnit dependency.

## Reproduce the separate Symfony consumer check

From the library checkout, run:

```bash
php tests/Package/check-symfony-consumer.php
```

This creates a fresh Symfony application from the public Doctrine publishing example under its own `App` namespace and installs copied versions of all seven packages. Its separate autoloader and database exercise the host's own Security configuration, web and MCP requests, enrollment and approval, stale content, revoked delegation, retries and receipt verification. See the [acceptance fixture](../../../tests/Adoption/symfony/README.md) for the proof's scope. It is example-derived adoption evidence; a first-time developer's independent integration remains a separate usability test.
