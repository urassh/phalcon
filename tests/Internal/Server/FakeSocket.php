<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server;

use Urassh\Phalcon\Internal\Server\Port\Socket;
use Urassh\Phalcon\Internal\Server\Port\SocketException;

final class FakeSocket implements Socket
{
  /** @var list<?string> read() が順に返す値。尽きたら null (WouldBlock) */
  public array $incoming = [];
  public string $written = '';
  /** @var ?int write() で書き込めるバイト数の残り。null なら無制限 */
  public ?int $writableBytes = null;
  public bool $failOnRead = false;
  public bool $closed = false;

  public function __construct(
    private readonly int $fd = 10,
  ) {
  }

  public function fd(): int
  {
    return $this->fd;
  }

  public function read(int $maxBytes): ?string
  {
    if ($this->failOnRead) {
      throw new SocketException('read failed');
    }

    return array_shift($this->incoming);
  }

  public function write(string $data): int
  {
    $length = $this->writableBytes === null ? strlen($data) : min(strlen($data), $this->writableBytes);
    if ($this->writableBytes !== null) {
      $this->writableBytes -= $length;
    }

    $this->written .= substr($data, 0, $length);

    return $length;
  }

  public function close(): void
  {
    $this->closed = true;
  }
}
