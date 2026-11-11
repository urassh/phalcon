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
   * 実際に待ち受けているアドレス ("127.0.0.1:8080" など)。ポート 0 で listen したときの確認に使う
   */
  public function localAddress(): string;

  /**
   * @return ?Socket null なら受け付け待ちの接続がない (WouldBlock)。返すソケットはノンブロッキング
   * @throws SocketException
   */
  public function accept(): ?Socket;

  public function close(): void;
}
