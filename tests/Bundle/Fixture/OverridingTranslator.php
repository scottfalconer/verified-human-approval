<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Fixture;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

/**
 * An application's translator, reduced to what the review pages use: the application's own messages for some
 * ids, and for the rest the id itself, with its parameters filled in, as Symfony's translator does for a message
 * no catalogue holds.
 */
final class OverridingTranslator implements TranslatorInterface, LocaleAwareInterface
{
    use TranslatorTrait {
        trans as private fillIn;
    }

    /** @param array<string, array<string, string>> $messages the application's messages, by domain and id */
    public function __construct(private readonly array $messages)
    {
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->fillIn($this->messages[$domain ?? 'messages'][$id ?? ''] ?? $id, $parameters, $domain, $locale);
    }
}
