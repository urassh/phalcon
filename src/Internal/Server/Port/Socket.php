<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * ノンブロッキングの接続済みソケット
 *
 * @internal
 */
interface Socket
{
  public function fd(): int;

  /**
   * @return ?string null ならいま読めるデータがない (WouldBlock)。'' なら相手が送信を終えた (EOF)
   * @throws SocketException
   */
  public function read(int $maxBytes): ?string;

  /**
   * @return int 書き込めたバイト数。0 ならいま書き込めない (WouldBlock)
   * @throws SocketException
   */
  public function write(string $data): int;

  public function close(): void;
}
