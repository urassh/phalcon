<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * ノンブロッキングの待ち受けソケット
 *
 * @internal
 */
interface ListeningSocket
{
  public function fd(): int;

  /**
   * @return ?Socket null なら受け付け待ちの接続がない (WouldBlock)。返すソケットはノンブロッキング
   * @throws SocketException
   */
  public function accept(): ?Socket;

  public function close(): void;
}
