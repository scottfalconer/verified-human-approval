<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

final class PdoAuditStoreTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function journals(): iterable
    {
        yield 'rollback journal' => ['DELETE'];
        yield 'WAL' => ['WAL'];
    }

    #[DataProvider('journals')]
    public function testJoinedAppendReservesTheWriterBeforeCallingItsBuilderOnAnEmptyChain(string $journal): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vha-audit-');
        try {
            $first = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $first->exec('PRAGMA journal_mode='.$journal);
            $first->exec('CREATE TABLE probe (value INTEGER)');
            $first->exec('INSERT INTO probe VALUES (0)');
            $store = new PdoAuditStore($first);
            $store->initialize();
            $other = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $other->exec('PRAGMA busy_timeout = 0');
            $first->beginTransaction();
            $called = 0;
            $store->append(static function (?array $head) use ($other, &$called): array {
                ++$called;
                self::assertNull($head);
                $other->beginTransaction();
                try {
                    $other->exec('UPDATE probe SET value = 1');
                    self::fail('Another SQLite writer entered while the audit record was being built.');
                } catch (\PDOException $error) {
                    self::assertStringContainsString('locked', $error->getMessage());
                } finally {
                    $other->rollBack();
                }

                return ['seq' => 1, 'tenant_id' => 'tenant', 'type' => 'test', 'subject' => 'subject', 'entry' => '{}', 'hash' => 'hash', 'key_id' => 'key', 'signature' => 'signature'];
            });
            self::assertSame(1, $called);
            self::assertTrue($first->inTransaction(), 'Appending did not commit the host transaction.');
            $first->rollBack();
            self::assertSame([], iterator_to_array((static function () use ($store): \Generator { yield from $store->records(); })()));
        } finally {
            unset($other, $store, $first);
            if (false !== $path) {
                foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }
}
