<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use VerifiedHumanApproval\Bundle\Twig\VerificationExtension;
use VerifiedHumanApproval\Bundle\Twig\VerificationRuntime;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/** The badge's Twig functions, loaded as TwigBundle loads them: through a runtime loader. */
final class VerificationExtensionTest extends TestCase
{
    public function testOnlyATemplateThatAsksForTheBadgeBuildsWhatItReads(): void
    {
        $built = 0;
        $store = $this->createMock(ApprovalStore::class);
        $store->expects(self::once())->method('get')->willReturn(null);
        $twig = new Environment(new ArrayLoader([
            'page' => '<h1>{{ title }}</h1>',
            'badge' => '{{ verified_human_approval_badge(id, "article-1") }}',
        ]));
        $twig->addExtension(new VerificationExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([VerificationRuntime::class => function () use (&$built, $store): VerificationRuntime {
            ++$built;

            return new VerificationRuntime(new PublicVerification($store, TrustedKeys::single('test', ChallengeBinding::encode(str_repeat('k', 32)))), $this->createStub(UrlGeneratorInterface::class), false);
        }]));

        self::assertSame('<h1>News</h1>', $twig->render('page', ['title' => 'News']));
        self::assertSame(0, $built, 'A page without a badge builds nothing the badge reads.');

        self::assertSame('', $twig->render('badge', ['id' => 'apr_'.str_repeat('a', 32)]), 'No stored request, no badge.');
        self::assertSame(1, $built);
    }
}
