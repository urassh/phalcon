<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\Linux;

use Urassh\Phalcon\Internal\Server\Port\ListeningSocket;
use Urassh\Phalcon\Internal\Server\Port\Platform;
use Urassh\Phalcon\Internal\Server\Port\Poller;

/**
 * epoll (エッジトリガ) と FFI 経由のソケット
 *
 * @internal
 */
final class LinuxPlatform implements Platform
{
  private const BACKLOG = 511;

  public function __construct(
    private readonly Libc $libc,
  ) {
  }

  public function listen(string $host, int $port): ListeningSocket
  {
    return new LinuxListeningSocket($this->libc, $this->libc->listenTcp($host, $port, self::BACKLOG));
  }

  public function createPoller(): Poller
  {
    return new EpollPoller($this->libc);
  }
}
