<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Port;

/**
 * OS ごとに異なるソケットと I/O 多重化の実装をまとめて提供する
 */
interface Platform
{
  /**
   * @param bool $reusePort SO_REUSEPORT を付ける。同じアドレスに複数のプロセスが listen でき、
   *                        カーネルが接続を振り分ける (振り分けるかは OS 次第)
   * @throws SocketException
   */
  public function listen(string $host, int $port, bool $reusePort = false): ListeningSocket;

  public function createPoller(): Poller;
}
