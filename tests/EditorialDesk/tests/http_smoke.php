<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir().'/editorial-http-'.bin2hex(random_bytes(8));
mkdir($runtime, 0700, true);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($listener)) {
    throw new RuntimeException('Cannot allocate a local test port.');
}
$port = (int) substr(strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
$origin = 'http://localhost:'.$port;
$fixture = proc_open([\PHP_BINARY, $root.'/tests/browser_fixture.php', $runtime, $origin], [1 => ['file', $runtime.'/fixture.log', 'w'], 2 => ['file', $runtime.'/fixture.log', 'a']], $pipes, $root);
if (!is_resource($fixture) || 0 !== proc_close($fixture)) {
    throw new RuntimeException('The isolated HTTP fixture failed.');
}
$fixtureData = json_decode(file_get_contents($runtime.'/fixture.json'), true, 512, \JSON_THROW_ON_ERROR);
$server = proc_open([\PHP_BINARY, '-S', 'localhost:'.$port, '-t', $root.'/public', $root.'/public/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $runtime.'/http.log', 'w'], 2 => ['file', $runtime.'/http.log', 'a']], $pipes, $root, array_merge(getenv(), $fixtureData['env']));
if (!is_resource($server)) {
    throw new RuntimeException('The local HTTP server did not start.');
}
$client = null;
$checks = [];
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) {throw new RuntimeException($message); }$checks[] = $message; };
try {
    $http = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
    $ready = false;
    for ($i = 0; $i < 100; ++$i) {
        try {
            $ready = 200 === $http->request('GET', $origin.'/login')->getStatusCode();
        } catch (Throwable) {
        }if ($ready) {
            break;
        }usleep(100000);
    }
    $check($ready, 'Application boots on a real local HTTP server');
    $browser = new HttpBrowser($http);
    $browser->followRedirects(false);
    $login = $browser->request('GET', $origin.'/login');
    $browser->submit($login->selectButton('Sign in')->form(['_username' => 'editor', '_password' => $fixtureData['accounts']['editor']['password']]));
    $check(302 === $browser->getResponse()->getStatusCode(), 'Real form login establishes the browser session');
    $browser->followRedirect();
    $browser->request('GET', $fixtureData['accounts']['editor']['passkey_link']);
    $browser->followRedirect();
    $key = new VirtualAuthenticator($origin);
    $registered = PasskeyPanel::on($browser)->register($key);
    $check('registered' === $registered->status, 'Trusted enrollment and real WebAuthn signatures pass through HTTP');
    $check(200 === $http->request('GET', $origin.'/bundles/verifiedhumanapproval/approval.js')->getStatusCode(), 'Production passkey JavaScript is served');
    $check(200 === $http->request('GET', $origin.'/app.css')->getStatusCode(), 'Application stylesheet is served');

    $factory = new Psr17Factory();
    $client = Client::builder()->setClientInfo('editorial-http-integration', '1.0')->setMaxRetries(0)->build();
    $client->connect(new HttpTransport(endpoint: $origin.'/mcp', headers: ['Authorization' => 'Bearer '.$fixtureData['accounts']['editor']['token']],
        httpClient: new Psr18Client($http, $factory, $factory), requestFactory: $factory, streamFactory: $factory));
    $list = $client->callTool('list_pages', []);
    $check(!$list->isError, 'Official MCP SDK discovers and reads the real authenticated endpoint');
    $draft = $client->callTool('save_draft', ['title' => 'Real HTTP notice', 'body' => 'The approved notice from the MCP client.']);
    $check(!$draft->isError, 'MCP saves a private working draft over HTTP');
    $id = $draft->structuredContent['id'];
    $args = ['page_id' => $id, 'request_key' => 'real-http-publication'];
    $pending = $client->callTool('publish_page', $args);
    $check(!$pending->isError && 'pending' === $pending->structuredContent['status'], 'MCP publication returns a pending review URL');
    $check(404 === $http->request('GET', $origin.'/pages/'.$id)->getStatusCode(), 'No public publication occurs before approval');
    $answer = PasskeyPanel::open($browser, $pending->structuredContent['review_url'])->approve($key);
    $check('completed' === $answer->status, 'Browser HTTP approval commits the MCP-requested proposal');
    $public = $http->request('GET', $origin.'/pages/'.$id);
    $html = $public->getContent(false);
    $check(200 === $public->getStatusCode() && str_contains($html, 'The approved notice from the MCP client.') && str_contains($html, 'vha-badge'), 'Public HTTP shows exact content and its verified badge');
    $retry = $client->callTool('publish_page', $args);
    $check(!$retry->isError && 'completed' === $retry->structuredContent['status'] && $retry->structuredContent['requestId'] === $pending->structuredContent['requestId'], 'Completed MCP retry returns the original request over HTTP');
    $next = $client->callTool('save_draft', ['page_id' => $id, 'title' => 'Private next edition', 'body' => 'PRIVATE NEXT EDITION CONTENT']);
    $check(!$next->isError, 'MCP saves a forward draft of a live page');
    $html = $http->request('GET', $origin.'/pages/'.$id)->getContent();
    $check(str_contains($html, 'vha-badge') && str_contains($html, 'The approved notice from the MCP client.') && !str_contains($html, 'PRIVATE NEXT EDITION CONTENT'), 'Forward draft preserves the live page and badge without leaking private text');

    $desk = $browser->request('GET', $origin.'/desk');
    $browser->submit($desk->selectButton('Create draft')->form(['title' => 'Web form notice', 'body' => 'The approved notice from the browser form.']));
    $location = $browser->getResponse()->getHeader('Location');
    $check(is_string($location) && 1 === preg_match('#/desk/pages/([a-f0-9]{32})$#', $location, $match), 'Browser form creates its own draft over HTTP');
    $webId = $match[1];
    $page = $browser->followRedirect();
    $browser->submit($page->selectButton('Review saved draft for publication')->form());
    $review = $browser->getResponse()->getHeader('Location');
    $check(is_string($review) && str_contains($review, '/approval/apr_'), 'Browser publication uses the bundle review route');
    $answer = PasskeyPanel::open($browser, $origin.$review)->approve($key);
    $check('completed' === $answer->status, 'Browser-originated publication completes through HTTP approval');
    $html = $http->request('GET', $origin.'/pages/'.$webId)->getContent();
    $check(str_contains($html, 'The approved notice from the browser form.') && str_contains($html, 'vha-badge'), 'Browser-originated publication has exact public content and badge');
    $browser->request('GET', $origin.'/approval/'.$pending->structuredContent['requestId'].'/evidence');
    $evidence = CanonicalJson::map(CanonicalJson::decode((string) $browser->getResponse()->getContent()));
    $keys = TrustedKeys::fromDocument($http->request('GET', $origin.'/approval/receipt-keys.json')->toArray());
    $verified = (new ReceiptVerifier())->verify($evidence, $keys);
    $check(Receipt::COMMIT === $verified['receiptSignedAt'], 'Downloaded receipt independently verifies using public HTTP signing keys');
    $report = ['passed' => true, 'checks' => $checks, 'transport' => 'real TCP HTTP, Symfony HttpBrowser, official MCP SDK',
        'authenticator' => 'software ES256 through production WebAuthn verifier', 'javascript_executed' => false, 'physical_device_tested' => false];
    if (!is_dir($root.'/evidence')) {
        mkdir($root.'/evidence', 0777, true);
    }
    file_put_contents($root.'/evidence/http-results.json', json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    echo json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
} finally {
    if ($client) {
        try {
            $client->disconnect();
        } catch (Throwable) {
        }
    }
    proc_terminate($server);
    proc_close($server);
    (new Filesystem())->remove($runtime);
}
