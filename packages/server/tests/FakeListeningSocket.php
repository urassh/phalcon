<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use Urassh\Phalcon\Server\Port\ListeningSocket;
use Urassh\Phalcon\Server\Port\Socket;

final class FakeListeningSocket implements ListeningSocket
{
  public const FD = 3;

  /** @var list<Socket> accept() が順に返す接続。尽きたら null (WouldBlock) */
  public array $pending = [];

  public function fd(): int
  {
    return self::FD;
  }

  public function localAddress(): string
  {
    return '127.0.0.1:8080';
  }

  public function accept(): ?Socket
  {
    return array_shift($this->pending);
  }

  public function close(): void
  {
  }
}
