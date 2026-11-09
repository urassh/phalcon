<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server;

use Urassh\Phalcon\Internal\Server\Port\ListeningSocket;
use Urassh\Phalcon\Internal\Server\Port\Socket;

final class FakeListeningSocket implements ListeningSocket
{
  public const FD = 3;

  /** @var list<Socket> accept() が順に返す接続。尽きたら null (WouldBlock) */
  public array $pending = [];

  public function fd(): int
  {
    return self::FD;
  }

  public function accept(): ?Socket
  {
    return array_shift($this->pending);
  }

  public function close(): void
  {
  }
}
