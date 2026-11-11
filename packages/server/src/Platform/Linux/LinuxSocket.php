<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\Linux;

use Urassh\Phalcon\Internal\Server\Port\Socket;

/**
 * @internal
 */
final class LinuxSocket implements Socket
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

  public function read(int $maxBytes): ?string
  {
    return $this->libc->recv($this->fd, $maxBytes);
  }

  public function write(string $data): int
  {
    return $this->libc->send($this->fd, $data);
  }

  public function close(): void
  {
    $this->libc->close($this->fd);
  }
}
