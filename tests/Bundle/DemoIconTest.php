<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

/** Every kind of page the demo shows names its icon, and the PHP server the demo runs under serves it. */
final class DemoIconTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private string $runtime;
    /** @var array<string, array{password: string}> */
    private array $accounts;
    /** @var list<KernelBrowser> */
    private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-icon-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
        // The demo trusts its own host only, and Symfony keeps that for the whole process.
        Request::setTrustedHosts([]);
    }

    public function testEveryKindOfPageNamesAnIconThePhpServerServes(): void
    {
        $visitor = $this->browser();
        $icons = [
            'the sign-in page, in the site’s layout' => self::icon($visitor->request('GET', self::ORIGIN.'/login')),
            'the alerts page with no alert issued' => self::icon($visitor->request('GET', self::ORIGIN.'/alerts')),
        ];
        // Under the initial rule the manager issues the alert directly, then sees its review page.
        $manager = $this->signedIn('manager');
        $manager->submit($manager->request('GET', self::ORIGIN.'/')->selectButton('Issue alert')->form());
        $icons['the review page'] = self::icon($manager->followRedirect());
        $icons['the alerts page with the alert issued'] = self::icon($visitor->request('GET', self::ORIGIN.'/alerts'));

        foreach ($this->served(array_unique($icons)) as $path => [$status, $type, $body]) {
            self::assertSame(200, $status, $path.' for '.implode(', ', array_keys($icons, $path, true)));
            self::assertStringStartsWith('image/svg+xml', $type, $path);
            self::assertSame('svg', simplexml_load_string($body)?->getName(), $path);
        }
    }

    /** The one icon the page names. */
    private static function icon(Crawler $page): string
    {
        $links = $page->filter('head link[rel~="icon"]');
        self::assertCount(1, $links, $page->getUri() ?? '');

        return (string) $links->attr('href');
    }

    /**
     * Requests each path from the demo served as its guide and demo/bin/server serve it: php -S with
     * demo/public/index.php in front. Without workers, it is one process, so stopping it stops all of it.
     *
     * @param array<string> $paths
     *
     * @return array<string, array{int, string, string}> each path's status, content type and body
     */
    private function served(array $paths): array
    {
        $root = \dirname(__DIR__, 2);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $environment = array_filter(['PHP_CLI_SERVER_WORKERS' => null, 'VERIFIED_HUMAN_APPROVAL_RUNTIME' => $this->runtime] + getenv(), \is_string(...));
        $server = proc_open([\PHP_BINARY, '-S', 'localhost:'.$port, '-t', $root.'/demo/public', $root.'/demo/public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $this->runtime.'/server.log', 'w'], 2 => ['file', $this->runtime.'/server.log', 'a']], $pipes, $root, $environment);
        self::assertIsResource($server);
        try {
            for ($attempt = 0; $attempt < 50 && false === ($connection = @stream_socket_client('tcp://localhost:'.$port, timeout: 1)); ++$attempt) {
                usleep(100_000);
            }
            self::assertIsResource($connection, 'The PHP server did not start: '.file_get_contents($this->runtime.'/server.log'));
            fclose($connection);
            $client = HttpClient::create(['timeout' => 5, 'max_redirects' => 0]);
            $served = [];
            foreach ($paths as $path) {
                $response = $client->request('GET', 'http://localhost:'.$port.$path);
                $served[$path] = [$response->getStatusCode(), $response->getHeaders(false)['content-type'][0] ?? '', $response->getContent(false)];
            }

            return $served;
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private function browser(): KernelBrowser
    {
        return $this->browsers[] = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
    }

    private function signedIn(string $user): KernelBrowser
    {
        $browser = $this->browser();
        $browser->submit($browser->request('GET', self::ORIGIN.'/login')->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());

        return $browser;
    }
}
