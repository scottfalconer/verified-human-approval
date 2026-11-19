<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * The signed log's rows on DBAL are read with the columns a record carries and no others. A reading of the chain
 * fetches every row at once, so a column it has no use for, such as the subject's digest that only the index needs,
 * costs memory at every entry.
 */
final class DbalAuditStoreTest extends TestCase
{
    private ?string $location = null;

    protected function tearDown(): void
    {
        if (null !== $this->location) {
            TestDatabase::drop('sqlite', $this->location);
        }
    }

    public function testTheChainAndALookupFetchOnlyTheColumnsOfARecord(): void
    {
        $this->location = TestDatabase::create('sqlite');
        $template = TestDatabase::connect('sqlite', $this->location);
        ApprovalSchema::install($template);
        // The same database, on a connection that keeps the column names of every result it fetches.
        $connection = new class($template->getParams(), $template->getDriver(), $template->getConfiguration()) extends Connection {
            /** @var list<list<string>> */
            public array $fetched = [];

            public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
            {
                $result = parent::executeQuery($sql, $params, $types, $qcp);
                $this->fetched[] = array_map($result->getColumnName(...), range(0, $result->columnCount() - 1));

                return $result;
            }
        };
        $store = new DbalAuditStore(new DbalTransaction($connection));
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $log = new AuditLog($store, $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock());
        $log->append('acme', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $log->append('acme', 'connection.revoked', 'grant-1', ['userId' => 'alice', 'by' => 'alice']);

        $connection->fetched = [];
        self::assertCount(2, [...$store->records()]);
        self::assertCount(1, [...$store->recordsFor('passkey.enrolled', 'key-1')]);
        $record = ['seq', 'tenant_id', 'type', 'subject', 'entry', 'hash', 'key_id', 'signature'];
        self::assertSame([$record, $record], $connection->fetched, 'Neither the chain nor a lookup fetches the subject digest.');
    }
}
