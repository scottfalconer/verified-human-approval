# Test your protected action

Development helpers for testing Verified Human Approval through your application's HTTP boundary:

| Helper | What it exercises |
| --- | --- |
| `VirtualAuthenticator` | A software ES256 passkey whose real signatures go through the production WebAuthn verifier |
| `PasskeyPanel` | The JSON and CSRF protocol used by the bundle's passkey panel, through a BrowserKit browser |
| `PanelAnswer` | The application's HTTP status, JSON data, operation status and refusal reason |
| `KernelHttpClient` | A PSR-18 client that sends requests through a fresh Symfony kernel without a web server or port |

Install this package as a **development dependency**. Its software passkeys simulate user presence and verification; they establish nothing about a real person, physical device or biometric. Use only isolated test accounts and databases.

The packages are not published on Packagist yet. First configure the path repository described in the [Symfony installation guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/symfony-bundle/docs/index.md#1-install-from-a-checkout), including the version entry for `verified-human-approval/testing`, then:

```bash
composer require --dev verified-human-approval/testing:dev-main
```

The package does not install PHPUnit, the MCP SDK or any Symfony bundle. Keep your application's test runner; supply a PSR-17 implementation such as `nyholm/psr7` when using `KernelHttpClient`.

## Approve through the page

Use a BrowserKit browser already signed in through your application's normal test fixtures. Enroll the software passkey through your trusted test enrollment flow first. The passkey's origin must match the application's configured WebAuthn origin.

```php
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

$passkey = new VirtualAuthenticator('https://approval.test');

// Your account page may use the bundle's registration-mode panel.
$registered = PasskeyPanel::open($browser, 'https://approval.test/account/passkeys')
    ->register($passkey);
self::assertSame('registered', $registered->status, (string) $registered);

// $reviewUrl came from the application's protected tool or request service.
$answer = PasskeyPanel::open($browser, $reviewUrl)->approve($passkey);
self::assertSame(200, $answer->httpStatus, (string) $answer);
self::assertSame('completed', $answer->status, (string) $answer);
self::assertNull(PasskeyPanel::find($browser));
```

The account page and its authorization are yours to supply; the bundle supplies the approval review page. `register()` is for `data-mode="registration"`; `approve()` handles approval and other assertion panels. `on($browser)` takes the current page's panel without another GET. On pages with multiple panels, pass the selected crawler as the second argument to `on()` or `find()`.

`approve()`, `register()` and `cancel()` reload the panel's page after receiving a JSON answer, including a refusal, so tests can assert its current rendered state. The production script can keep the existing page after a refusal. BrowserKit does not execute JavaScript; these helpers exercise the server protocol, not its visual behavior or browser security enforcement.

## Assert the outcome, including refusal

`PanelAnswer::refused()` reports an HTTP status outside 200–299. Read `status` as well: HTTP 200 can contain `invalidated`, with no business mutation. The complete JSON object is in `data`; casting the answer to a string gives a useful assertion message.

```php
$panel = PasskeyPanel::open($browser, $reviewUrl);
$ceremony = $panel->begin();
self::assertFalse($ceremony->refused(), (string) $ceremony);

// Edit the draft and increment its revision through your host fixture here.
$credential = $passkey->assertion($ceremony->data['publicKey']);
$answer = $panel->complete($ceremony->data['ceremony_id'], $credential);
self::assertSame('invalidated', $answer->status, (string) $answer);
self::assertSame('stale_resource', $answer->reason);
```

`begin()` leaves the browser on its JSON answer and does not reload. If your enrollment endpoint does not name a ceremony, use `complete(null, $credential)`. To test a signed negative case, pass changes such as `['origin' => 'https://other.test']` to `assertion()` or `registration()`. For an assertion without user verification, use `assertion($options, ['flags' => 0x01])`. `canCancel()` tells whether the rendered panel offers cancellation; `cancel()` throws if it does not.

## Call your MCP endpoint in process

```php
use App\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use VerifiedHumanApproval\Testing\KernelHttpClient;

$factory = new Psr17Factory();
$http = new KernelHttpClient(
    static fn () => new Kernel('test', false),
    $factory,
    $factory,
);
```

Give `$http` to the MCP SDK's `HttpTransport` as `httpClient`, with `$factory` as `requestFactory` and `streamFactory`. Its requests run through your real endpoint, authentication and tool services. Your host issues the test bearer token; this package creates no accounts, sessions or access tokens. A kernel built with debug off, as here, reuses the container it compiled before: clear the test cache after changing configuration.

The client preserves request headers and bodies, HTTP error responses and streamed SSE content. It keeps no cookie jar. Each request obtains a new kernel; a terminable kernel is terminated after sending the response, and a Symfony kernel is shut down even when a request fails. Exceptions raised by your in-process application remain visible to the test.

See the [Symfony testing guide](https://github.com/scottfalconer/verified-human-approval/blob/main/src/symfony-bundle/docs/testing.md) for test environment setup, MCP wiring, receipt verification and the cases a host integration should cover. The repository's [whole-application test](https://github.com/scottfalconer/verified-human-approval/blob/main/tests/Testing/DemoApplicationTest.php) exercises registration, MCP requests, approval, cancellation, stale data, handoff and receipt download using these public helpers.

This package is developed in the [Verified Human Approval repository](https://github.com/scottfalconer/verified-human-approval). Open issues and pull requests there.
