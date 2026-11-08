<?php

declare(strict_types=1);

namespace ExactApproval\Core;

final class ChallengeBinding
{
    public static function challenge(string $proposalDigest, string $nonce): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $proposalDigest) || strlen($nonce) !== 32) {
            throw new \InvalidArgumentException('A binding needs a SHA-256 digest and a 32-byte random nonce.');
        }
        return hash('sha256', "ExactApproval/WebAuthn/v1\0".hex2bin($proposalDigest).$nonce, true);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
            throw new \InvalidArgumentException('Invalid base64url data.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || self::encode($decoded) !== $encoded) {
            throw new \InvalidArgumentException('Non-canonical base64url data.');
        }
        return $decoded;
    }
}
