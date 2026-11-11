<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server;

use Urassh\Phalcon\Internal\Server\Port\Clock;
use Urassh\Phalcon\Internal\Server\Port\ListeningSocket;
use Urassh\Phalcon\Internal\Server\Port\Poller;
use Urassh\Phalcon\Internal\Server\Port\SocketException;

/**
 * Poller の通知を受けて、接続の受け付けと各接続の読み書きを振り分ける
 *
 * @internal
 */
final class EventLoop
{
  public const TIMEOUT_MS = 5000;

  /** @var array<int, Connection> fd => 接続 */
  private array $connections = [];
  private bool $listening = false;

  public function __construct(
    private readonly ListeningSocket $listener,
    private readonly Poller $poller,
    private readonly Protocol $protocol,
    private readonly Clock $clock,
  ) {
  }

  public function run(): never
  {
    while (true) {
      $this->runOnce();
    }
  }

  /**
   * 通知を 1 回待って処理する
   */
  public function runOnce(): void
  {
    if (!$this->listening) {
      $this->poller->add($this->listener->fd());
      $this->listening = true;
    }

    foreach ($this->poller->wait($this->nextTimeoutMs()) as $readiness) {
      if ($readiness->fd === $this->listener->fd()) {
        $this->acceptAll();
        continue;
      }

      $connection = $this->connections[$readiness->fd] ?? null;
      if ($connection === null) {
        continue;
      }

      if ($readiness->hangup) {
        $this->drop($connection);
        continue;
      }

      if ($readiness->readable) {
        $connection->onReadable();
      }
      if ($readiness->writable) {
        $connection->onWritable();
      }

      if ($connection->isFinished()) {
        $this->drop($connection);
      }
    }

    $this->dropExpired();
  }

  /**
   * 1 回の通知で複数の接続が溜まっていることがあるので、WouldBlock になるまで受け付ける
   */
  private function acceptAll(): void
  {
    while (true) {
      try {
        $socket = $this->listener->accept();
      } catch (SocketException) {
        return;
      }

      if ($socket === null) {
        return;
      }

      // 登録時点ですでに読めるデータがあれば、Poller はそれも通知する
      $this->poller->add($socket->fd());
      $this->connections[$socket->fd()] = new Connection(
        $socket,
        $this->protocol,
        $this->clock->nowMs() + self::TIMEOUT_MS,
      );
    }
  }

  private function drop(Connection $connection): void
  {
    // close すると fd 番号が再利用されうるので、先に監視から外す
    $this->poller->remove($connection->fd());
    unset($this->connections[$connection->fd()]);
    $connection->close();
  }

  private function dropExpired(): void
  {
    $now = $this->clock->nowMs();
    foreach ($this->connections as $connection) {
      if ($connection->isExpired($now)) {
        $this->drop($connection);
      }
    }
  }

  /**
   * いちばん近い期限までの時間。接続がなければ無期限に待つ
   */
  private function nextTimeoutMs(): ?int
  {
    if ($this->connections === []) {
      return null;
    }

    $nearest = min(array_map(fn (Connection $connection) => $connection->deadlineMs(), $this->connections));

    return max(0, $nearest - $this->clock->nowMs());
  }
}
