<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * @internal
 */
interface Clock
{
  /**
   * 単調増加する時刻 (ミリ秒)
   */
  public function nowMs(): int;
}
