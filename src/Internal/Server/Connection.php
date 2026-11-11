<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server;

use Urassh\Phalcon\Internal\Server\Port\Socket;
use Urassh\Phalcon\Internal\Server\Port\SocketException;

/**
 * 1 つの接続の状態 (受信中 → 送信中 → 終了) を管理する
 *
 * エッジトリガで通知される前提なので、通知のたびに WouldBlock になるまで読み書きしきる
 *
 * @internal
 */
final class Connection
{
  public const MAX_BUFFER_SIZE = 1024 * 1024;

  private const CHUNK_SIZE = 8192;

  private string $received = '';
  private string $outgoing = '';
  private bool $responded = false;
  private bool $finished = false;

  public function __construct(
    private readonly Socket $socket,
    private readonly Protocol $protocol,
    private readonly int $deadlineMs,
  ) {
  }

  public function fd(): int
  {
    return $this->socket->fd();
  }

  public function deadlineMs(): int
  {
    return $this->deadlineMs;
  }

  public function isExpired(int $nowMs): bool
  {
    return $nowMs >= $this->deadlineMs;
  }

  public function isFinished(): bool
  {
    return $this->finished;
  }

  public function onReadable(): void
  {
    // 応答を作ったあとに届いたデータは読まない (Connection: close なので次のリクエストはない)
    if ($this->finished || $this->responded) {
      return;
    }

    try {
      if ($this->readAll()) {
        $this->respond();
      }
    } catch (SocketException) {
      $this->finished = true;
    }
  }

  public function onWritable(): void
  {
    if ($this->finished || !$this->responded) {
      return;
    }

    try {
      $this->flush();
    } catch (SocketException) {
      $this->finished = true;
    }
  }

  public function close(): void
  {
    $this->finished = true;
    $this->socket->close();
  }

  /**
   * @return bool 応答を作れるだけのデータが揃ったか
   */
  private function readAll(): bool
  {
    while (true) {
      $chunk = $this->socket->read(self::CHUNK_SIZE);
      if ($chunk === null) {
        return false;
      }

      // 相手が送信を終えたので、ここまでに届いた分で応答する
      if ($chunk === '') {
        if ($this->received === '') {
          $this->finished = true;

          return false;
        }

        return true;
      }

      $this->received .= $chunk;
      if ($this->protocol->isComplete($this->received) || strlen($this->received) > self::MAX_BUFFER_SIZE) {
        return true;
      }
    }
  }

  private function respond(): void
  {
    $this->outgoing = $this->protocol->respond($this->received);
    $this->responded = true;
    $this->flush();
  }

  private function flush(): void
  {
    while ($this->outgoing !== '') {
      $written = $this->socket->write($this->outgoing);
      if ($written === 0) {
        return;
      }

      $this->outgoing = substr($this->outgoing, $written);
    }

    $this->finished = true;
  }
}
