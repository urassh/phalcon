<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Platform;

use Urassh\Phalcon\Server\Port\Clock;

final class SystemClock implements Clock
{
  public function nowMs(): int
  {
    return intdiv(hrtime(true), 1_000_000);
  }
}
