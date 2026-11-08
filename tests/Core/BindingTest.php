<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\PreparedAction;

final class BindingTest extends TestCase
{
    public function testObjectOrderDoesNotChangeCanonicalMeaningButValuesDo(): void
    {
        self::assertSame(
            CanonicalJson::encode(['z' => 'last', 'a' => ['second' => 2, 'first' => 1]]),
            CanonicalJson::encode(['a' => ['first' => 1, 'second' => 2], 'z' => 'last']),
        );
        self::assertNotSame(CanonicalJson::encode(['body' => 'A']), CanonicalJson::encode(['body' => 'A ']));
        self::assertNotSame(CanonicalJson::encode(['body' => 'A']), CanonicalJson::encode(['body' => 'B']));
        self::assertNotSame(CanonicalJson::encode(['effect' => (object) []]), CanonicalJson::encode(['effect' => []]));
        self::assertNotSame(CanonicalJson::encode((object) []), CanonicalJson::encode(['absent' => null]));
    }

    public function testCanonicalizationRetainsUnicodeAndUsesUtf16PropertyOrdering(): void
    {
        // The RFC 8785 §3.2.3 property-order example, without its floating-point values.
        $input = (object) [
            "\u{20ac}" => 'Euro Sign',
            "\r" => 'Carriage Return',
            "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One',
            "\u{1f600}" => 'Emoji: Grinning Face',
            "\u{80}" => 'Control',
            "\u{f6}" => 'Latin Small Letter O With Diaeresis',
        ];
        self::assertSame(
            '{"\\r":"Carriage Return","1":"One","'."\u{80}".'":"Control","ö":"Latin Small Letter O With Diaeresis","€":"Euro Sign","😀":"Emoji: Grinning Face","דּ":"Hebrew Letter Dalet With Dagesh"}',
            CanonicalJson::encode($input),
        );
        self::assertNotSame(CanonicalJson::encode('é'), CanonicalJson::encode("e\u{301}"), 'Approval text is not Unicode-normalized after review.');
    }

    public function testExactIntegerBoundaryAndNestedObjectRoundTrip(): void
    {
        self::assertSame('9007199254740991', CanonicalJson::encode(9007199254740991));
        $canonical = CanonicalJson::encode(['nested' => [(object) [], [], null, '0001', 0, false]]);
        self::assertSame($canonical, CanonicalJson::encode(CanonicalJson::decode($canonical)));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'unsafe integer' => [9007199254740992];
        yield 'decimal' => [0.1];
        yield 'infinity' => [\INF];
        yield 'nan' => [\NAN];
        yield 'invalid utf8' => ["\xFF"];
        yield 'arbitrary object' => [new \DateTimeImmutable('2026-11-08')];
    }

    #[DataProvider('invalidValues')]
    public function testUnsupportedValuesFailInsteadOfBeingSilentlyNormalized(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CanonicalJson::encode($value);
    }

    public function testChallengeCommitsToBothProposalAndFreshNonce(): void
    {
        $digestA = hash('sha256', 'proposal A');
        $digestB = hash('sha256', 'proposal B');
        $nonceA = str_repeat('a', 32);
        $nonceB = str_repeat('b', 32);
        $challenge = ChallengeBinding::challenge($digestA, $nonceA);
        self::assertSame(32, \strlen($challenge));
        self::assertNotSame($challenge, ChallengeBinding::challenge($digestB, $nonceA));
        self::assertNotSame($challenge, ChallengeBinding::challenge($digestA, $nonceB));
        self::assertSame($challenge, ChallengeBinding::decode(ChallengeBinding::encode($challenge)));
        // Independent recomputation, independent of any proposal/challenge lookup table.
        self::assertSame(hash('sha256', "VerifiedHumanApproval/WebAuthn/v1\0".hex2bin($digestA).$nonceA, true), $challenge);
    }

    public function testFrozenProposalIsNotChangedByLaterMutableObjects(): void
    {
        $nested = (object) ['message' => 'Approved'];
        $action = new PreparedAction('notice.publish', '1', ['tenant' => 't'], ['content' => $nested], [], ['title' => 'Notice'], ['title' => 'Notice']);
        $request = ApprovalRequest::create(new Actor('alice', 't', 'client'), $action, new ApprovalPolicy('notice.publish', '1', 'Publish {{ title }}.'), new \DateTimeImmutable('2026-11-08T10:00:00Z'), 'en');
        $canonical = $request->canonical;
        $nested->message = 'Substituted';
        self::assertSame($canonical, $request->canonical);
        self::assertSame('Approved', $request->prepared()->effect['content']->message);
        $decoded = $request->payload();
        $decoded['action']->effect->content->message = 'Another substitution';
        self::assertSame($canonical, $request->canonical);
        self::assertSame('Approved', $request->prepared()->effect['content']->message);
    }

    public function testStoredProposalTamperingIsDetected(): void
    {
        $action = new PreparedAction('notice.publish', '1', ['tenant' => 't'], ['body' => 'Approved'], [], ['title' => 'Notice']);
        $request = ApprovalRequest::create(new Actor('alice', 't'), $action, new ApprovalPolicy('notice.publish', '1', 'Publish the exact notice.'), new \DateTimeImmutable('2026-11-08T10:00:00Z'), 'en');
        $this->expectException(ApprovalException::class);
        new ApprovalRequest($request->id, str_replace('Approved', 'Changed!', $request->canonical), $request->digest);
    }

    public function testUnknownOrExecutableStatementPlaceholdersAreRejected(): void
    {
        $action = new PreparedAction('notice.publish', '1', ['tenant' => 't'], [], [], ['title' => 'Notice'], ['title' => 'Notice']);
        foreach (['Publish {{ unknown }}.', '{{ system("whoami") }}', '{{ title|raw }}'] as $template) {
            try {
                (new ApprovalPolicy('notice.publish', '1', $template))->statement($action);
                self::fail('An unsupported statement placeholder was accepted.');
            } catch (ApprovalException $exception) {
                self::assertSame('invalid_policy', $exception->reason);
            }
        }
    }

    public function testStatementSubstitutionIsTextNotTemplateExecution(): void
    {
        $action = new PreparedAction('notice.publish', '1', ['tenant' => 't'], [], [], ['title' => 'Notice'], ['title' => '<script>alert(1)</script>']);
        self::assertSame('Publish <script>alert(1)</script>.', (new ApprovalPolicy('notice.publish', '1', 'Publish {{ title }}.'))->statement($action));
        // The HTTP rendering layer must escape this text; the core binds its exact bytes.
    }
}
