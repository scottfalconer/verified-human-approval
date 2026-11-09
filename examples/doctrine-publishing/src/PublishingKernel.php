<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A plain Symfony application: config/bundles.php, config/packages/, config/services.php and config/routes.php, as
 * in a project made with `symfony new`. APP_CACHE_DIR and APP_LOG_DIR move its var/ directories, as usual.
 */
final class PublishingKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }
}
