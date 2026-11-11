<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * @internal
 */
final class Readiness
{
  /**
   * @param bool $hangup エラーまたは双方向の切断で、この fd がもう使えない。
   *                     相手が送信側だけを閉じた場合は readable になり、read() が '' を返す
   */
  public function __construct(
    public readonly int $fd,
    public readonly bool $readable = false,
    public readonly bool $writable = false,
    public readonly bool $hangup = false,
  ) {
  }
}
