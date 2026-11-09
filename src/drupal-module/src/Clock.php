<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Psr\Clock\ClockInterface;

final class Clock implements ClockInterface {

  public function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
  }

}

