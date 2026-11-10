<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Testing\PanelAnswer;

final class PanelAnswerTest extends TestCase
{
    public function testTheStatusAndTheReasonAreTheBodysWhenTheyAreStrings(): void
    {
        $refusal = ['status' => 'rejected', 'reason' => 'stale_resource', 'request_status' => 'invalidated'];
        $answer = new PanelAnswer(409, $refusal);
        self::assertSame(409, $answer->httpStatus);
        self::assertSame('rejected', $answer->status);
        self::assertSame('stale_resource', $answer->reason);
        self::assertSame($refusal, $answer->data);

        // A body is whatever the application sent: a test that reads ->status gets a string or nothing.
        $answer = new PanelAnswer(200, ['status' => 200, 'reason' => ['stale_resource']]);
        self::assertNull($answer->status);
        self::assertNull($answer->reason);
        self::assertSame(['status' => 200, 'reason' => ['stale_resource']], $answer->data);

        $answer = new PanelAnswer(200, []);
        self::assertNull($answer->status);
        self::assertNull($answer->reason);
    }

    #[DataProvider('statuses')]
    public function testOnlyAnHttpErrorStatusIsARefusal(int $status, bool $refused): void
    {
        self::assertSame($refused, (new PanelAnswer($status, ['status' => 'completed']))->refused());
    }

    public static function statuses(): iterable
    {
        yield 'below the successes' => [199, true];
        yield 'the first success' => [200, false];
        yield 'the last success' => [299, false];
        yield 'a redirect the browser did not follow' => [302, true];
        yield 'a conflict' => [409, true];
        yield 'too many requests' => [429, true];
        yield 'a server error' => [500, true];
    }

    public function testAnAnswerThatWasNotRefusedCanStillSayTheActionDidNotRun(): void
    {
        $answer = new PanelAnswer(200, ['status' => 'invalidated', 'reason' => 'stale_resource']);

        self::assertFalse($answer->refused());
        self::assertSame('invalidated', $answer->status);
    }

    public function testAsAStringItIsTheStatusAndTheWholeBody(): void
    {
        self::assertSame('HTTP 200 {}', (string) new PanelAnswer(200, []));
        self::assertSame(
            'HTTP 409 {"status":"rejected","message":"Déjà approuvé : https://app.test/approval/apr_1","request_status":"completed"}',
            (string) new PanelAnswer(409, ['status' => 'rejected', 'message' => 'Déjà approuvé : https://app.test/approval/apr_1', 'request_status' => 'completed']),
        );
        self::assertSame('HTTP 200 {"0":"a","1":"b"}', (string) new PanelAnswer(200, ['a', 'b']), 'The body is always shown as the object it was.');
        // An assertion's message must never be what fails: text that is not UTF-8 is left out, not thrown on.
        self::assertSame('HTTP 500 {"status":null}', (string) new PanelAnswer(500, ['status' => "\xB1\x31"]));
    }
}
