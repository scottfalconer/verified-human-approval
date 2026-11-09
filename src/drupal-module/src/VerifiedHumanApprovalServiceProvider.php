<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/** Collects action adapters using ordinary Drupal service tags. */
final class VerifiedHumanApprovalServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    $actions = [];
    foreach ($container->findTaggedServiceIds('verified_human_approval.action') as $id => $tags) {
      $actions[] = new Reference($id);
    }
    $container->getDefinition('verified_human_approval.action_registry')->setArgument(0, $actions);
  }

}

