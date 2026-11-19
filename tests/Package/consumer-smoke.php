<?php

declare(strict_types=1);

// Runs inside a separate Composer project, with only that project's autoloader: what another application,
// a Drupal module say, gets from the packages.
// Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp|doctrine|testing>

use Composer\InstalledVersions;
use Doctrine\DBAL\DriverManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Response as BrowserResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PanelAnswer;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

[, $consumer, $variant] = $argv + [null, null, null];
if (!is_string($consumer) || !in_array($variant, ['webauthn', 'mcp', 'doctrine', 'testing'], true)) {
    fwrite(\STDERR, "Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp|doctrine|testing>\n");
    exit(2);
}
require $consumer.'/vendor/autoload.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ').$what."\n";
    if (!$ok) {
        $failures[] = $what;
    }
};
$vendor = (string) realpath($consumer.'/vendor');
$from = static fn (string $class): string => (string) (new ReflectionClass($class))->getFileName();

$check(str_starts_with($from(ApprovalService::class), $vendor.'/verified-human-approval/core/'), 'core loads from the consumer’s own vendor directory');
$check(str_starts_with($from(WebAuthnVerifier::class), $vendor.'/verified-human-approval/webauthn/'), 'webauthn loads from the consumer’s own vendor directory');
foreach (['symfony/framework-bundle', 'symfony/security-bundle', 'symfony/twig-bundle', 'symfony/mcp-bundle', 'verified-human-approval/symfony-bundle'] as $package) {
    $check(!InstalledVersions::isInstalled($package), $package.' is not installed');
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$credentials = new PdoCredentialRepository($pdo);
$credentials->initialize();
$verifier = new WebAuthnVerifier(new Configuration('consumer.test', 'https://consumer.test'), $credentials);
$person = new Actor('alice', 'tenant-a');
$options = $verifier->registrationOptions($person, random_bytes(32), 'Alice');
$check('consumer.test' === ($options['rp']['id'] ?? null) && is_string($options['challenge'] ?? null), 'WebAuthn builds registration options');

if ('mcp' === $variant) {
    $check(str_starts_with($from(ApprovalCard::class), $vendor.'/verified-human-approval/mcp/'), 'mcp loads from the consumer’s own vendor directory');
    $resource = ApprovalCard::resource('https://consumer.test', 'approval_status');
    $check(ApprovalCard::URI === $resource->uri && str_contains((string) $resource->text, 'ui/initialize'), 'the MCP card resource loads from the installed package');
} else {
    $check(!InstalledVersions::isInstalled('verified-human-approval/mcp'), 'mcp is not pulled in when it is not asked for');
}

if ('doctrine' === $variant) {
    $check(str_starts_with($from(DbalTransaction::class), $vendor.'/verified-human-approval/doctrine/'), 'doctrine loads from the consumer’s own vendor directory');
    $check(!InstalledVersions::isInstalled('doctrine/orm'), 'the ORM stays optional');
    // The three stores on one DBAL connection, in one transaction, as an application without a framework uses them.
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    ApprovalSchema::install($connection);
    $transaction = new DbalTransaction($connection);
    $signer = new SodiumEvidenceSigner('consumer-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $log = new AuditLog(new DbalAuditStore($transaction), $signer, $signer->publicKey(), new class implements Psr\Clock\ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-09T10:00:00Z');
        }
    });
    $anchored = new AnchoredCredentialRepository(new DbalCredentialRepository($transaction), $log);
    $credential = new Credential('cred-1', 'alice', 'tenant-a', '{"publicKeyCredentialId":"Y3JlZC0x","credentialPublicKey":"a2V5","userHandle":"aGFuZGxl"}');
    $transaction->run(static function () use ($anchored, $credential): void {
        $anchored->add($credential);
        $anchored->anchor($credential, ['method' => 'consumer check']);
    });
    $check(null !== $anchored->find('cred-1') && $log->verify()['intact'], 'a passkey enrolled through the Doctrine stores is vouched for by the signed log');
    try {
        $connection->transactional(static fn () => (new DbalApprovalStore($transaction))->transaction(static fn () => null));
        $check(false, 'the approval transaction refuses an outer one');
    } catch (LogicException) {
        $check(true, 'the approval transaction refuses an outer one');
    }
}

if ('testing' === $variant) {
    $check([] === array_filter(
        [VirtualAuthenticator::class, PasskeyPanel::class, PanelAnswer::class, KernelHttpClient::class],
        static fn (string $class): bool => !str_starts_with($from($class), $vendor.'/verified-human-approval/testing/'),
    ), 'testing loads from the consumer’s own vendor directory');
    foreach (['phpunit/phpunit', 'mcp/sdk'] as $package) {
        $check(!InstalledVersions::isInstalled($package), 'the testing package does not bring '.$package);
    }

    // The software passkey, with the installed verifier deciding: nothing here is a test double of the library.
    $passkey = new VirtualAuthenticator('https://consumer.test');
    $registered = $verifier->register($person, $options, $passkey->registration($options));
    $check($passkey->id() === $registered->id, 'the installed verifier registers the software passkey');

    // The approval page's script, played against an application of three answers: the page with its panel, a
    // ceremony, and the installed verifier's word on the signature.
    $browser = new class($verifier, $person) extends AbstractBrowser {
        /** @var list<string> */
        public array $requests = [];
        private string $challenge = '';
        private bool $approved = false;

        public function __construct(private readonly WebAuthnVerifier $verifier, private readonly Actor $person)
        {
            parent::__construct();
        }

        protected function doRequest(object $request): BrowserResponse
        {
            $this->requests[] = $request->getMethod().' '.$request->getUri();
            $json = static fn (array $data, int $status = 200): BrowserResponse => new BrowserResponse(json_encode($data, \JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json']);
            if ('POST' === $request->getMethod() && 'csrf-1' !== ($request->getServer()['HTTP_X_CSRF_TOKEN'] ?? null)) {
                return $json(['status' => 'rejected', 'reason' => 'csrf_invalid'], 403);
            }

            switch ($request->getMethod().' '.parse_url($request->getUri(), \PHP_URL_PATH)) {
                case 'GET /approval/apr_1':
                    $page = $this->approved
                        ? '<p>Approved with your passkey.</p>'
                        : '<div data-passkey-panel data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete"><button data-approve>Approve</button><p data-message></p></div>';

                    return new BrowserResponse('<!DOCTYPE html><html><head><title>Review</title></head><body>'.$page.'</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                case 'POST /approval/apr_1/options':
                    $this->challenge = random_bytes(32);

                    return $json(['ceremony_id' => 'ceremony-1', 'publicKey' => $this->verifier->requestOptions($this->person, $this->challenge)]);
                case 'POST /approval/apr_1/complete':
                    $sent = json_decode((string) $request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
                    try {
                        $this->verifier->verify($this->person, $this->challenge, $sent['credential']);
                    } catch (ApprovalException $refused) {
                        return $json(['status' => 'rejected', 'reason' => $refused->reason], 409);
                    }
                    $this->approved = true;

                    return $json(['status' => 'completed']);
                default:
                    return new BrowserResponse('', 404);
            }
        }
    };
    $page = 'https://consumer.test/approval/apr_1';
    $refused = PasskeyPanel::open($browser, $page)->approve(new VirtualAuthenticator('https://consumer.test'));
    $check($refused->refused() && 409 === $refused->httpStatus && 'credential_unavailable' === $refused->reason, 'a passkey that was never registered is refused through the panel: '.$refused);
    $approved = PasskeyPanel::on($browser)->approve($passkey);
    $check(!$approved->refused() && 'completed' === $approved->status, 'the registered passkey approves through the panel: '.$approved);
    $check(null === PasskeyPanel::find($browser), 'the browser is left on the page as it now stands, which has no panel');
    $check([
        'GET '.$page, 'POST '.$page.'/options', 'POST '.$page.'/complete', 'GET '.$page,
        'POST '.$page.'/options', 'POST '.$page.'/complete', 'GET '.$page,
    ] === $browser->requests, 'the panel sent the ceremony and completion requests in order, and reloaded after each answer');

    // An MCP client's request, answered by a kernel inside this process: what a streamed answer flushes comes
    // back as the body, and none of it reaches this script's output.
    $factory = new Psr17Factory();
    $kernel = new class implements HttpKernelInterface {
        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
        {
            $seen = $request->getMethod().' '.$request->getPathInfo().' '.$request->headers->get('Authorization').' '.$request->getContent();

            return new StreamedResponse(static function () use ($seen): void {
                echo "event: message\n";
                @ob_flush();
                flush();
                echo 'data: '.$seen."\n\n";
            }, 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'session-1']);
        }
    };
    $answer = (new KernelHttpClient(static fn () => $kernel, $factory, $factory))->sendRequest(
        $factory->createRequest('POST', 'https://consumer.test/mcp')->withHeader('Authorization', 'Bearer token-1')->withBody($factory->createStream('{"jsonrpc":"2.0"}')),
    );
    $check(
        200 === $answer->getStatusCode() && 'session-1' === $answer->getHeaderLine('Mcp-Session-Id')
        && "event: message\ndata: POST /mcp Bearer token-1 {\"jsonrpc\":\"2.0\"}\n\n" === (string) $answer->getBody(),
        'the kernel client answers a PSR-18 request from a kernel in this process, a streamed answer included',
    );
}

exit([] === $failures ? 0 : 1);
