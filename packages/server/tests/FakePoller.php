<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use Urassh\Phalcon\Server\Port\Poller;
use Urassh\Phalcon\Server\Port\Readiness;

final class FakePoller implements Poller
{
  /** @var list<list<Readiness>> wait() が順に返す通知。尽きたら空 */
  public array $notifications = [];
  /** @var list<int> */
  public array $watching = [];
  /** @var list<?int> wait() に渡されたタイムアウト */
  public array $timeouts = [];

  public function add(int $fd): void
  {
    $this->watching[] = $fd;
  }

  public function remove(int $fd): void
  {
    $this->watching = array_values(array_filter($this->watching, fn (int $watched) => $watched !== $fd));
  }

  public function wait(?int $timeoutMs): array
  {
    $this->timeouts[] = $timeoutMs;

    return array_shift($this->notifications) ?? [];
  }
}
