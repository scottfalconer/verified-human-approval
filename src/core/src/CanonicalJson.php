<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

use Root23\JsonCanonicalizer\JsonCanonicalizer;

/** RFC 8785 canonicalization restricted to exact, interoperable input values. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        self::validate($value, 0);

        $sorted = (new JsonCanonicalizer())->canonicalize($value);
        // root23 1.0.1 sorts correctly but PHP's default encoder escapes U+2028/U+2029.
        // Re-emit its ordered object graph with the RFC 8785 string flags. Our accepted
        // numeric profile contains exact integers only, so this cannot round numbers.
        return json_encode(self::decode($sorted), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 64, JSON_THROW_ON_ERROR);
    }

    /** Preserve nested {} versus [] when reading records with known top-level objects. */
    public static function map(mixed $value): array
    {
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }

        return get_object_vars($value);
    }

    private static function validate(mixed $value, int $depth): void
    {
        if ($depth > 48) {
            throw new \InvalidArgumentException('Approval data exceeds the maximum nesting depth.');
        }
        if ($value === null || is_bool($value)) {
            return;
        }
        if (is_int($value)) {
            if ($value < -9007199254740991 || $value > 9007199254740991) {
                throw new \InvalidArgumentException('Encode integers outside the exact JSON range as strings.');
            }
            return;
        }
        if (is_string($value)) {
            if (!preg_match('//u', $value)) {
                throw new \InvalidArgumentException('Approval data must be valid UTF-8.');
            }
            return;
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $key => $child) {
                self::validate((string) $key, $depth + 1);
                self::validate($child, $depth + 1);
            }
            return;
        }
        throw new \InvalidArgumentException('Use strings for decimals; objects must be stdClass, and values must be JSON data.');
    }
}
