<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Editing;

use VerifiedHumanApproval\Core\ApprovalException;

/**
 * The one check an article's text passes, from a form or from an agent, before it is saved as a draft or frozen into a
 * proposal: what is reviewed is exactly what is stored.
 */
final class ArticleText
{
    public const TITLE_LENGTH = 200;
    public const BODY_LENGTH = 20_000;

    public static function title(mixed $title): string
    {
        $title = \is_string($title) && mb_check_encoding($title, 'UTF-8') ? trim(preg_replace('/\s+/u', ' ', $title) ?? '') : '';
        if ('' === $title || mb_strlen($title) > self::TITLE_LENGTH) {
            throw new ApprovalException('invalid_input', 'Give the article a title of 1 to '.self::TITLE_LENGTH.' characters.');
        }

        return $title;
    }

    public static function body(mixed $body): string
    {
        // Browsers send a textarea's line breaks as CRLF: stored, reviewed and shown as LF.
        $body = \is_string($body) && mb_check_encoding($body, 'UTF-8') ? trim(str_replace(["\r\n", "\r"], "\n", $body)) : '';
        if ('' === $body || mb_strlen($body) > self::BODY_LENGTH) {
            throw new ApprovalException('invalid_input', 'Give the article a text of 1 to '.self::BODY_LENGTH.' characters.');
        }

        return $body;
    }
}
