<?php

declare(strict_types=1);

namespace App\Workflow;

use VerifiedHumanApproval\Core\ApprovalException;

/** Plain text is rendered escaped by the same content partial in public and review pages. */
final class Text
{
    public static function title(mixed $value): string
    {
        if (!\is_string($value) || 1 !== preg_match('//u', $value)) {
            throw new ApprovalException('invalid_input', 'The title must be valid UTF-8 text.');
        }
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ApprovalException('invalid_input', 'Use a single-line title of 1–200 characters.');
        }

        return $value;
    }

    public static function body(mixed $value): string
    {
        if (!\is_string($value) || 1 !== preg_match('//u', $value)) {
            throw new ApprovalException('invalid_input', 'The body must be valid UTF-8 text.');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ('' === trim($value) || \strlen($value) > 20_000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new ApprovalException('invalid_input', 'Use non-empty plain text of at most 20,000 bytes.');
        }

        return $value;
    }
}
