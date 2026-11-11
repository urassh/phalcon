<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * OS ごとに異なるソケットと I/O 多重化の実装をまとめて提供する
 *
 * @internal
 */
interface Platform
{
  /**
   * @throws SocketException
   */
  public function listen(string $host, int $port): ListeningSocket;

  public function createPoller(): Poller;
}
