<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\Linux;

use Urassh\Phalcon\Internal\Server\Port\ListeningSocket;
use Urassh\Phalcon\Internal\Server\Port\Socket;

/**
 * @internal
 */
final class LinuxListeningSocket implements ListeningSocket
{
  public function __construct(
    private readonly Libc $libc,
    private readonly int $fd,
  ) {
  }

  public function fd(): int
  {
    return $this->fd;
  }

  public function localAddress(): string
  {
    return $this->libc->localAddress($this->fd);
  }

  public function accept(): ?Socket
  {
    $fd = $this->libc->accept($this->fd);

    return $fd === null ? null : new LinuxSocket($this->libc, $fd);
  }

  public function close(): void
  {
    $this->libc->close($this->fd);
  }
}
