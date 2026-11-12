<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Platform\StreamSelect;

/**
 * ソケットと Poller が共有する、ストリームと通知の待ち状態の表
 *
 * stream_select はレベルトリガなので、そのままだと書き込み可能な間ずっと通知が鳴り続ける。
 * 方向ごとに「通知待ち」かどうかを持ち、通知したら外し、WouldBlock になったら戻すことで
 * エッジトリガの約束 (WouldBlock まで読み書きしきれば、次の変化で再び通知される) を満たす
 *
 * @internal
 */
final class Streams
{
  /** @var array<int, resource> */
  private array $resources = [];
  /** @var array<int, true> */
  private array $readArmed = [];
  /** @var array<int, true> */
  private array $writeArmed = [];

  /**
   * @param resource $stream
   * @return int fd の代わりに使う ID。プロセス内で再利用されない
   */
  public function register(mixed $stream): int
  {
    $id = get_resource_id($stream);
    $this->resources[$id] = $stream;
    // epoll と同じく、登録した時点の状態も 1 回は通知する
    $this->readArmed[$id] = true;
    $this->writeArmed[$id] = true;

    return $id;
  }

  public function unregister(int $id): void
  {
    unset($this->resources[$id], $this->readArmed[$id], $this->writeArmed[$id]);
  }

  /**
   * @return ?resource
   */
  public function get(int $id): mixed
  {
    return $this->resources[$id] ?? null;
  }

  public function isReadArmed(int $id): bool
  {
    return isset($this->readArmed[$id]);
  }

  public function isWriteArmed(int $id): bool
  {
    return isset($this->writeArmed[$id]);
  }

  public function armRead(int $id): void
  {
    if (isset($this->resources[$id])) {
      $this->readArmed[$id] = true;
    }
  }

  public function armWrite(int $id): void
  {
    if (isset($this->resources[$id])) {
      $this->writeArmed[$id] = true;
    }
  }

  public function disarmRead(int $id): void
  {
    unset($this->readArmed[$id]);
  }

  public function disarmWrite(int $id): void
  {
    unset($this->writeArmed[$id]);
  }
}
