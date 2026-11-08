<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

final class AllClearCommandTest extends TestCase
{
    public function testTakesTheLiveAlertDownAndKeepsTheRecord(): void
    {
        $runtime = sys_get_temp_dir().'/verified-human-approval-all-clear-'.bin2hex(random_bytes(8));
        DemoSetup::initialize($runtime, 'http://localhost:8080');
        $kernel = new DemoKernel($runtime, 'test');
        try {
            $pdo = DemoDatabase::connect($runtime.'/application.sqlite');
            $pdo->exec("UPDATE demo_announcements SET published_revision = revision, published_title = title, published_body = body, mutation_count = 1 WHERE id = 'riverside-evacuation'");
            $database = new DemoDatabase($pdo);
            self::assertNotNull($database->liveAlert());

            $tester = new CommandTester((new Application($kernel))->find('demo:all-clear'));
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('All clear', $tester->getDisplay());
            self::assertNull($database->liveAlert(), 'The public page shows no active alerts again.');
            self::assertSame(1, (int) $database->announcement('riverside-evacuation')['mutation_count'], 'The record of what was issued stays.');

            $tester->execute([]);
            self::assertStringContainsString('Already clear', $tester->getDisplay());
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($runtime);
        }
    }
}
