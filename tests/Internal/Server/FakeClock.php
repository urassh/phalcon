<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server;

use Urassh\Phalcon\Internal\Server\Port\Clock;

final class FakeClock implements Clock
{
  public int $now = 0;

  public function nowMs(): int
  {
    return $this->now;
  }
}
