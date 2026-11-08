<?php

declare(strict_types=1);

namespace Drupal\exact_approval_test\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Site\Settings;
use Symfony\Component\Routing\RouteCollection;

/** Enables personal HTTP Basic authentication only on the disposable test site. */
final class McpTestRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    if (Settings::get('exact_approval_acceptance_test') === TRUE
      && ($route = $collection->get('mcp_server.handle')) !== NULL) {
      $route->setOption('_auth', ['basic_auth']);
    }
  }
}

