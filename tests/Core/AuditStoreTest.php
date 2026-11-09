<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;

/**
 * The audit log's signing and checking need no database. What every store must do (rows kept exactly, appenders
 * serialized before the head is read, a host's transaction joined and never ended, nothing failing halfway) is
 * the audit storage contract in tests/Contract, run on each store.
 */
final class AuditStoreTest extends TestCase
{
    public function testTheLogSignsAndChecksOverAStoreWithoutPdo(): void
    {
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $store = new InMemoryAuditStore();
        $log = new AuditLog($store, $signer, $signer->publicKey(), new MockClock('2026-11-09 10:00:00 UTC'));
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $log->append('tenant-b', 'passkey.enrolled', 'key-2', ['userId' => 'bob']);
        $log->append('tenant-a', 'alert.taken_down', 'alert-1', []);

        $report = $log->verify();
        self::assertTrue($report['intact'], 'One chain spans both tenants.');
        self::assertSame([1, 2, 3], array_column($report['entries'], 'seq'));
        self::assertSame('tenant-b', $log->find('passkey.enrolled', 'key-2')['tenantId']);
        self::assertStringContainsString('"data":{}', $store->rows[3]['entry']);
        self::assertSame(3, $store->builds, 'One build per append.');

        $store->rows[2]['tenant_id'] = 'tenant-a';
        self::assertFalse($log->verify()['intact'], 'Checking needs no database.');
        self::assertNull($log->find('passkey.enrolled', 'key-2'));
    }
}
