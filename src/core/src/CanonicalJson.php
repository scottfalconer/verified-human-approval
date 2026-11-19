<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * RFC 8785 canonicalization restricted to exact, interoperable input values. Object members are sorted by the
 * UTF-16 code units of their names, strings carry only the escapes the RFC requires, and numbers are exact
 * integers only, so there is no number formatting to agree on. The bytes depend on the value alone, never on
 * the process's settings.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        self::validate($value, 0);

        return json_encode(self::sorted($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 64, \JSON_THROW_ON_ERROR);
    }

    /**
     * Preserve nested {} versus [] when reading records with known top-level objects.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }

        return get_object_vars($value);
    }

    /**
     * Lists stay lists; objects, and arrays that are not lists, become objects with their members in RFC 8785
     * order. Names are converted from UTF-8 explicitly, whatever mbstring's internal encoding is.
     */
    private static function sorted(mixed $value): mixed
    {
        if (\is_array($value) && array_is_list($value)) {
            return array_map(self::sorted(...), $value);
        }
        if (!\is_array($value) && !$value instanceof \stdClass) {
            return $value;
        }
        $members = \is_array($value) ? $value : get_object_vars($value);
        $units = [];
        foreach (array_keys($members) as $name) {
            $units[$name] = mb_convert_encoding((string) $name, 'UTF-16BE', 'UTF-8');
        }
        // Big-endian code units compare byte by byte in the order RFC 8785 sets.
        asort($units, \SORT_STRING);
        $object = new \stdClass();
        foreach (array_keys($units) as $name) {
            $object->{$name} = self::sorted($members[$name]);
        }

        return $object;
    }

    private static function validate(mixed $value, int $depth): void
    {
        if ($depth > 48) {
            throw new \InvalidArgumentException('Approval data exceeds the maximum nesting depth.');
        }
        if (null === $value || \is_bool($value)) {
            return;
        }
        if (\is_int($value)) {
            if ($value < -9007199254740991 || $value > 9007199254740991) {
                throw new \InvalidArgumentException('Encode integers outside the exact JSON range as strings.');
            }

            return;
        }
        if (\is_string($value)) {
            if (!preg_match('//u', $value)) {
                throw new \InvalidArgumentException('Approval data must be valid UTF-8.');
            }

            return;
        }
        if (\is_array($value) || $value instanceof \stdClass) {
            foreach (\is_array($value) ? $value : get_object_vars($value) as $key => $child) {
                // PHP objects cannot hold such a name, so the data could never be read back.
                if (str_starts_with((string) $key, "\0")) {
                    throw new \InvalidArgumentException('Approval data cannot use an object key that starts with a NUL byte.');
                }
                self::validate((string) $key, $depth + 1);
                self::validate($child, $depth + 1);
            }

            return;
        }
        throw new \InvalidArgumentException('Use strings for decimals; objects must be stdClass, and values must be JSON data.');
    }
}
