<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

/** A demo opened at an address other than the one it was set up for says where to open it. */
final class DemoOriginTest extends TestCase
{
    private string $runtime;
    /** @var list<KernelBrowser> */
    private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-origin-test-'.bin2hex(random_bytes(8));
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

    public function testALocalDemoOpenedAtAnotherAddressNamesItsOwn(): void
    {
        DemoSetup::initialize($this->runtime, 'http://localhost:8080');
        foreach (['the right host on another port' => 'localhost:8090', 'another name for this computer' => '127.0.0.1:8080'] as $case => $host) {
            $browser = $this->browser(['HTTP_HOST' => $host]);
            $browser->request('GET', '/login');
            self::assertSame(421, $browser->getResponse()->getStatusCode(), $case);
            self::assertStringContainsString('This demo runs at http://localhost:8080. Open it there', (string) $browser->getResponse()->getContent(), $case);
            self::assertStringNotContainsString($host, (string) $browser->getResponse()->getContent(), $case.': the answer names only the demo’s own address.');
        }
        $browser = $this->browser(['HTTP_HOST' => 'localhost:8080']);
        $browser->request('GET', '/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
    }

    public function testBehindTheTunnelTheAddressIsTheOneTheLocalProxyForwards(): void
    {
        DemoSetup::initialize($this->runtime, 'https://alerts.example');
        // The tunnel connects from this computer and names the public scheme in its forwarded headers.
        $tunnel = ['HTTP_HOST' => 'alerts.example', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '127.0.0.1'];
        $browser = $this->browser($tunnel);
        $browser->request('GET', '/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        foreach (['without the tunnel' => ['HTTP_X_FORWARDED_PROTO' => 'http'], 'forwarded by someone else' => ['REMOTE_ADDR' => '203.0.113.7']] as $case => $change) {
            $browser = $this->browser($change + $tunnel);
            $browser->request('GET', '/login');
            self::assertSame(421, $browser->getResponse()->getStatusCode(), $case);
        }
    }

    /** @param array<string, string> $server */
    private function browser(array $server): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), $server);
        $browser->followRedirects(false);

        return $this->browsers[] = $browser;
    }
}
