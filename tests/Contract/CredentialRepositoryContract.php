<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\WebAuthn\Credential;

/**
 * The raw passkey storage contract (the rules on RevocableCredentialRepository), through a harness: an id is never
 * overwritten, reassigned or reactivated; ids are exact, so ids differing only in case are different passkeys;
 * ownership is user and tenant together; and update() compares and replaces the unchanged, active source, so a
 * zero-counter passkey can write back the very same source. To run it for another store, write a
 * CredentialHarness for it and a test class that extends this one and names that harness.
 */
abstract class CredentialRepositoryContract extends TestCase
{
    private string $location;

    /** @return class-string<CredentialHarness> */
    abstract protected static function harness(): string;

    protected function setUp(): void
    {
        $this->location = static::harness()::prepare();
    }

    protected function tearDown(): void
    {
        static::harness()::destroy($this->location);
    }

    public function testAZeroCounterPasskeyCanWriteBackTheSameSource(): void
    {
        $harness = $this->connect();
        $credential = new Credential('key-1', 'editor', 'acme', '{"counter":0}', false, 'Passkey');
        $harness->transaction(static fn () => $harness->repository()->add($credential));
        // The identical source is still the expected, active row, whatever count of changed rows the database reports.
        $harness->transaction(static fn () => $harness->repository()->update($credential, $credential->sourceJson));
        $harness->transaction(static fn () => $harness->repository()->update($credential, $credential->sourceJson));
        self::assertSame($credential->sourceJson, $this->connect()->repository()->find('key-1')?->sourceJson);
        $this->assertRefused('credential_changed', $harness, static fn () => $harness->repository()->update($credential, '{"counter":5}'));

        $advanced = new Credential('key-1', 'editor', 'acme', '{"counter":1}', false, 'Passkey');
        $harness->transaction(static fn () => $harness->repository()->update($advanced, $credential->sourceJson));
        self::assertSame('{"counter":1}', $this->connect()->repository()->find('key-1')?->sourceJson);
        $this->assertRefused('credential_changed', $harness, static fn () => $harness->repository()->update($advanced, $credential->sourceJson), 'The source it expected has been replaced.');
    }

    public function testAnExistingIdIsNeverOverwrittenReassignedOrReactivated(): void
    {
        $harness = $this->connect();
        $original = new Credential('key-1', 'editor', 'acme', '{"counter":0}', false, 'Laptop');
        $harness->transaction(static fn () => $harness->repository()->add($original));
        foreach ([
            'the same record again' => $original,
            'another owner' => new Credential('key-1', 'mallory', 'acme', '{"counter":9}', false, 'Stolen'),
            'another tenant' => new Credential('key-1', 'editor', 'other', '{"counter":9}', false, 'Stolen'),
        ] as $what => $duplicate) {
            $this->assertRefused('credential_exists', $harness, static fn () => $harness->repository()->add($duplicate), $what);
        }
        self::assertEquals($original, $this->connect()->repository()->find('key-1'));

        self::assertTrue($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('editor', 'acme'), 'key-1')));
        $this->assertRefused('credential_exists', $harness, static fn () => $harness->repository()->add($original), 'Adding a revoked id again would reactivate it.');
        self::assertTrue($this->connect()->repository()->find('key-1')?->revoked, 'It stays revoked.');
    }

    public function testIdsDifferingOnlyInCaseOrSpacingAreDifferentPasskeys(): void
    {
        $harness = $this->connect();
        $long = str_repeat('Ab_-', 341); // 1,364 characters, the longest id WebAuthn allows
        $records = [
            new Credential('key-1', 'editor', 'acme', '{"n":1}'),
            new Credential('KEY-1', 'mallory', 'acme', '{"n":2}'),
            new Credential('key-1 ', 'mallory', 'acme', '{"n":3}'),
            new Credential($long, 'editor', 'acme', '{"n":4}'),
        ];
        foreach ($records as $record) {
            $harness->transaction(static fn () => $harness->repository()->add($record));
        }
        $reader = $this->connect()->repository();
        foreach ($records as $record) {
            self::assertEquals($record, $reader->find($record->id));
        }
        self::assertNull($reader->find('Key-1'));
        $owned = array_map(static fn (Credential $credential): string => $credential->id, $reader->forActor(new Actor('editor', 'acme')));
        sort($owned);
        self::assertSame([$long, 'key-1'], $owned);

        self::assertFalse($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('mallory', 'acme'), 'key-1')), 'Mallory owns KEY-1, not key-1.');
        self::assertTrue($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('mallory', 'acme'), 'KEY-1')));
        self::assertFalse($this->connect()->repository()->find('key-1')?->revoked);
        self::assertTrue($this->connect()->repository()->find('KEY-1')?->revoked);
    }

    public function testOwnershipIsUserAndTenantTogether(): void
    {
        $harness = $this->connect();
        $credential = new Credential('key-1', 'editor', 'acme', '{"counter":0}');
        $harness->transaction(static fn () => $harness->repository()->add($credential));
        $harness->transaction(static fn () => $harness->repository()->add(new Credential('key-2', 'editor', 'other', '{"counter":0}')));
        self::assertSame(['key-1'], array_map(static fn (Credential $credential): string => $credential->id, $this->connect()->repository()->forActor(new Actor('editor', 'acme'))));
        self::assertSame([], $this->connect()->repository()->forActor(new Actor('Editor', 'acme')));

        foreach ([new Actor('editor', 'other'), new Actor('mallory', 'acme'), new Actor('Editor', 'acme')] as $stranger) {
            self::assertFalse($harness->transaction(static fn (): bool => $harness->repository()->revoke($stranger, 'key-1')), 'Revocation needs the owner.');
        }
        foreach ([new Credential('key-1', 'mallory', 'acme', '{"counter":1}'), new Credential('key-1', 'editor', 'other', '{"counter":1}')] as $stranger) {
            $this->assertRefused('credential_changed', $harness, static fn () => $harness->repository()->update($stranger, $credential->sourceJson), 'An update must name the owner.');
        }
        self::assertEquals($credential, $this->connect()->repository()->find('key-1'), 'Nothing a stranger tried changed it.');

        self::assertTrue($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('editor', 'acme'), 'key-1')));
        self::assertFalse($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('editor', 'acme'), 'key-1')), 'Revoking again changes nothing.');
        self::assertFalse($harness->transaction(static fn (): bool => $harness->repository()->revoke(new Actor('editor', 'acme'), 'key-404')));
        $this->assertRefused('credential_changed', $harness, static fn () => $harness->repository()->update($credential, $credential->sourceJson), 'A revoked passkey cannot be written back.');
        self::assertSame([], $this->connect()->repository()->forActor(new Actor('editor', 'acme')));
        self::assertTrue($this->connect()->repository()->find('key-1')?->revoked, 'A revoked record is still found, so a verifier can fail closed.');
    }

    private function connect(): CredentialHarness
    {
        return static::harness()::connect($this->location);
    }

    /** @param callable(): mixed $write */
    private function assertRefused(string $reason, CredentialHarness $harness, callable $write, string $message = ''): void
    {
        try {
            $harness->transaction($write);
            self::fail('Refused: '.$message);
        } catch (ApprovalException $error) {
            self::assertSame($reason, $error->reason, $message);
        }
    }
}
