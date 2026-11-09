<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\user\UserInterface;
use VerifiedHumanApproval\Core\Actor;

/**
 * Trusted identity integration; never construct an actor from tool/form input.
 *
 * An OAuth integration may replace this service. It must bind a stable grant ID
 * and revalidate revocation/scopes in accountFor(), including on later requests.
 */
interface ActorProviderInterface {

  public function current(): Actor;

  public function interactive(): Actor;

  /** Reload and validate the actor's account and, if present, delegation. */
  public function accountFor(Actor $actor): UserInterface;

}

