<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** A demo runtime made before alerts linked to their approval keeps working: it gains the one column, and nothing else changes. */
final class DemoUpgradeTest extends TestCase
{
    public function testAnOlderRuntimeGainsTheApprovalLinkColumn(): void
    {
        $path = sys_get_temp_dir().'/verified-human-approval-upgrade-'.bin2hex(random_bytes(6)).'.sqlite';
        try {
            $old = new \PDO('sqlite:'.$path);
            $old->exec('CREATE TABLE demo_alerts (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, revision INTEGER NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL, published_revision INTEGER, published_title TEXT, published_body TEXT, mutation_count INTEGER NOT NULL DEFAULT 0)');
            $old->exec("INSERT INTO demo_alerts (id, tenant_id, revision, title, body) VALUES ('a', 't', 1, 'Title', 'Body')");
            unset($old);

            $pdo = DemoDatabase::connect($path);
            self::assertContains('published_request_id', $pdo->query('PRAGMA table_info(demo_alerts)')->fetchAll(\PDO::FETCH_COLUMN, 1));
            self::assertSame(['id' => 'a', 'published_request_id' => null], $pdo->query('SELECT id, published_request_id FROM demo_alerts')->fetch());
            // Connecting again changes nothing.
            DemoDatabase::connect($path);
        } finally {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
