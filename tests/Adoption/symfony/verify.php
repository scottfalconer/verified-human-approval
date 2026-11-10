<?php

declare(strict_types=1);

use App\PublishingKernel;
use App\Setup\Installer;
use Doctrine\DBAL\DriverManager;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

// This file is copied into the fresh application's root and uses only its installed packages.
require __DIR__.'/vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo 'ok   '.$message."\n";
};
foreach (['core', 'webauthn', 'mcp', 'symfony-bundle', 'doctrine', 'store-contracts', 'testing'] as $name) {
    $installed = __DIR__.'/vendor/verified-human-approval/'.$name;
    $check(is_file($installed.'/composer.json') && !is_link($installed), $name.' is an installed copy');
}
$check(!class_exists('VerifiedHumanApproval\\Tests\\Bundle\\DemoRuntime'), 'The monorepo test namespace is absent');
$check(!class_exists('VerifiedHumanApproval\\Demo\\DemoKernel'), 'The demo application is absent');

$origin = 'https://adoption.example';
$state = __DIR__.'/var/state';
$databaseFile = __DIR__.'/var/application.sqlite';
mkdir($state, 0700, true);
foreach ([
    'APP_ENV' => 'test', 'APP_DEBUG' => '0',
    'APP_CACHE_DIR' => __DIR__.'/var/cache', 'APP_LOG_DIR' => __DIR__.'/var/log',
    'APP_STATE_DIR' => $state, 'APP_ORIGIN' => $origin,
    'APP_SECRET' => bin2hex(random_bytes(32)), 'DATABASE_URL' => 'sqlite:///'.$databaseFile,
    'APPROVAL_RECEIPT_KEY_ID' => 'adoption-key',
    'APPROVAL_RECEIPT_SECRET_KEY' => ChallengeBinding::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
    'APPROVAL_RECEIPT_KEY_VALID_FROM' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
    'MCP_REQUEST_STATE_KEY' => bin2hex(random_bytes(32)),
] as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

$kernels = [];
$kernel = static function () use (&$kernels): PublishingKernel {
    $instance = new PublishingKernel('test', false);
    $kernels[] = $instance;

    return $instance;
};
$bootstrap = $kernel();
$bootstrap->boot();
$installer = $bootstrap->getContainer()->get('test.service_container')->get(Installer::class);
$accounts = $installer->install($origin);
$database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databaseFile]);
$browsers = [];
$clients = [];

try {
    $login = static function (string $user) use ($kernel, $origin, $accounts, $check, &$browsers): KernelBrowser {
        $browser = new KernelBrowser($kernel(), ['HTTP_HOST' => 'adoption.example', 'HTTPS' => 'on']);
        $browsers[] = $browser;
        $page = $browser->request('GET', $origin.'/login');
        $browser->submit($page->selectButton('Sign in')->form(['_username' => $user, '_password' => $accounts[$user]['password']]));
        $check(302 === $browser->getResponse()->getStatusCode(), $user.' signs in through the application form');
        $browser->followRedirect();
        $check(200 === $browser->getResponse()->getStatusCode(), $user.' has a normal Symfony Security session');

        return $browser;
    };
    $editor = $login('editor');
    $editor->request('GET', $accounts['editor']['passkey_link']);
    $check(302 === $editor->getResponse()->getStatusCode(), 'The host enrollment link authorizes this account');
    $passkey = new VirtualAuthenticator($origin);
    $registration = PasskeyPanel::open($editor, $origin.'/account/passkey')->register($passkey);
    $check('registered' === $registration->status, 'Installed helpers enroll a passkey through the host panel');

    $form = static fn (): string => (string) $editor->request('GET', $origin.'/editor')->filter('input[name=_token]')->first()->attr('value');
    $createDraft = static function (string $title, string $body) use ($editor, $origin, $form, $check): string {
        $editor->request('POST', $origin.'/editor/articles', ['_token' => $form(), 'title' => $title, 'body' => $body]);
        $location = (string) $editor->getResponse()->headers->get('Location');
        $check(1 === preg_match('#/editor/articles/([0-9a-f]{16})$#', $location, $match), 'The host web form creates an ordinary draft');

        return $match[1];
    };
    $row = static fn (string $id): array|false => $database->fetchAssociative('SELECT * FROM publishing_articles WHERE id = ?', [$id]);
    $approve = static function (string $id, ?Closure $between = null) use ($editor, $origin, $passkey): array {
        $panel = PasskeyPanel::open($editor, $origin.'/approval/'.$id);
        $ceremony = $panel->begin();
        if ($ceremony->refused()) {
            return $ceremony->data;
        }
        if (null !== $between) {
            $between();
        }

        return $panel->complete($ceremony->data['ceremony_id'], $passkey->assertion($ceremony->data['publicKey']))->data;
    };

    $webArticle = $createDraft('Consumer web article', 'The exact text the editor reviews.');
    $editor->request('POST', $origin.'/editor/articles/'.$webArticle.'/publish', ['_token' => $form()]);
    $location = (string) $editor->getResponse()->headers->get('Location');
    $check(1 === preg_match('#^/approval/(apr_[0-9a-f]{32})\?return=#', $location, $match), 'A web publication request opens the installed review');
    $webRequest = $match[1];
    $check(null === $row($webArticle)['live_title'], 'The separate database connection sees no web publication before approval');
    $check('completed' === $approve($webRequest)['status'], 'A web request executes after real signed approval');
    $check('Consumer web article' === $row($webArticle)['live_title'] && 1 === (int) $row($webArticle)['revision'], 'Exactly the reviewed web content committed once');

    $factory = new Psr17Factory();
    $agent = Client::builder()->setClientInfo('fresh-symfony-consumer', '1.0')->setMaxRetries(0)->build();
    $agent->connect(new HttpTransport(
        endpoint: $origin.'/mcp', headers: ['Authorization' => 'Bearer '.$accounts['editor']['agent_token']],
        httpClient: new KernelHttpClient($kernel, $factory, $factory), requestFactory: $factory, streamFactory: $factory,
    ));
    $clients[] = $agent;
    $list = $agent->callTool('list_articles', []);
    $check(!$list->isError && count($list->structuredContent['articles']) >= 4, 'The official SDK reads through the host authenticated MCP endpoint');
    $draft = $agent->callTool('save_draft', ['title' => 'Consumer MCP article', 'body' => 'The frozen agent text.']);
    $check(!$draft->isError && 'draft' === $draft->structuredContent['status'], 'The host MCP tool saves an ordinary draft');
    $article = $draft->structuredContent['id'];
    $arguments = ['article_id' => $article, 'request_key' => 'consumer-stale'];
    $request = $agent->callTool('publish_article', $arguments);
    $check(!$request->isError && 'pending' === $request->structuredContent['status'], 'An MCP protected write is pending');
    $requestId = $request->structuredContent['requestId'];
    $check(null === $row($article)['live_title'], 'The separate database connection sees no MCP publication before approval');
    $retry = $agent->callTool('publish_article', $arguments);
    $check($requestId === $retry->structuredContent['requestId'], 'An identical pending retry reuses the approval request');

    $reader = $login('reader');
    $reader->request('GET', $origin.'/approval/'.$requestId);
    $check(404 === $reader->getResponse()->getStatusCode(), 'Another real host account cannot discover this approval');
    $stale = $approve($requestId, static function () use ($editor, $origin, $form, $article): void {
        $editor->request('POST', $origin.'/editor/articles/'.$article.'/draft', ['_token' => $form(), 'title' => 'Changed after review', 'body' => 'This must require a new request.']);
    });
    $check('invalidated' === $stale['status'] && 'stale_resource' === $stale['reason'], 'Changed content invalidates an already-started passkey ceremony');
    $check(null === $row($article)['live_title'], 'A stale approval writes no protected content');

    $arguments['request_key'] = 'consumer-approved';
    $request = $agent->callTool('publish_article', $arguments);
    $requestId = $request->structuredContent['requestId'];
    $check('completed' === $approve($requestId)['status'], 'A fresh MCP proposal executes after its owner approves');
    $retry = $agent->callTool('publish_article', $arguments);
    $check('completed' === $retry->structuredContent['status'] && $requestId === $retry->structuredContent['requestId'], 'A completed MCP retry returns the original outcome');
    $check('Changed after review' === $row($article)['live_title'] && 1 === (int) $row($article)['revision'], 'The approved MCP version committed exactly once');
    $status = $agent->callTool('approval_status', ['request_id' => $requestId]);
    $check('completed' === $status->structuredContent['status'] && true === $status->structuredContent['approval']['approvedWithPasskey'], 'The initiating connection reads the completed passkey outcome');

    $editor->request('GET', $origin.'/approval/'.$requestId.'/evidence');
    $check(200 === $editor->getResponse()->getStatusCode(), 'The installed evidence route exports the committed report');
    $evidence = CanonicalJson::map(CanonicalJson::decode((string) $editor->getResponse()->getContent()));
    $visitor = new KernelBrowser($kernel(), ['HTTP_HOST' => 'adoption.example', 'HTTPS' => 'on']);
    $browsers[] = $visitor;
    $visitor->request('GET', $origin.'/approval/receipt-keys.json');
    $keys = TrustedKeys::fromDocument(json_decode((string) $visitor->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR));
    $verified = (new ReceiptVerifier())->verify($evidence, $keys);
    $check(Receipt::COMMIT === $verified['receiptSignedAt'], 'An independent installed verifier checks the receipt using only public site keys');
    $visitor->request('GET', $origin.'/');
    $check(200 === $visitor->getResponse()->getStatusCode() && 2 === $visitor->getCrawler()->filter('.vha-badge')->count(), 'Anonymous public content shows verified badges for both approved paths');

    $deniedArticle = $createDraft('Revoked connection article', 'This text must stay unpublished.');
    $request = $agent->callTool('publish_article', ['article_id' => $deniedArticle, 'request_key' => 'consumer-revoked']);
    $denied = $approve($request->structuredContent['requestId'], static fn () => $database->executeStatement("UPDATE publishing_agent_connections SET revoked_at = 1 WHERE user_id = 'editor'"));
    $check('denied' === $denied['status'] && 'delegation_denied' === $denied['reason'], 'Revoking the host grant during the ceremony stops execution');
    $check(null === $row($deniedArticle)['live_title'], 'A revoked connection leaves the article unpublished');
    echo 'Fresh Symfony adoption: '.$checks." checks passed.\n";
} finally {
    foreach ($clients as $client) {
        try {
            $client->disconnect();
        } catch (Throwable) {
            // The final scenario deliberately revoked this connection.
        }
    }
    foreach ($browsers as $browser) {
        $browser->getKernel()->shutdown();
    }
    foreach ($kernels as $instance) {
        $instance->shutdown();
    }
    $database->close();
}
