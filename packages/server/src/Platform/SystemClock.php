<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform;

use Urassh\Phalcon\Internal\Server\Port\Clock;

/**
 * @internal
 */
final class SystemClock implements Clock
{
  public function nowMs(): int
  {
    return intdiv(hrtime(true), 1_000_000);
  }
}
