<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use VerifiedHumanApproval\Core\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalStringTest extends TestCase
{
    public function testLineAndParagraphSeparatorsStayLiteralPerRfc8785(): void
    {
        self::assertSame("\"\u{2028}\u{2029}\"", CanonicalJson::encode("\u{2028}\u{2029}"));
        self::assertSame("{\"\u{2028}\":\"text\",\"\u{2029}\":{}}", CanonicalJson::encode((object) ["\u{2029}" => new \stdClass(), "\u{2028}" => 'text']));
    }

    public function testLiteralBackslashEscapeSequenceIsNotConfusedWithUnicodeSeparator(): void
    {
        self::assertNotSame(CanonicalJson::encode('\\u2028'), CanonicalJson::encode("\u{2028}"));
        self::assertSame('\\u2028', CanonicalJson::decode(CanonicalJson::encode('\\u2028')));
        self::assertSame('{"a":[],"z":{}}', CanonicalJson::encode((object) ['z' => new \stdClass(), 'a' => []]));
    }
}
