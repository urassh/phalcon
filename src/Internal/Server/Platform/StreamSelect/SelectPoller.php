<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\StreamSelect;

use Urassh\Phalcon\Internal\Server\Port\Poller;
use Urassh\Phalcon\Internal\Server\Port\Readiness;

/**
 * @internal
 */
final class SelectPoller implements Poller
{
  /** @var array<int, true> */
  private array $watching = [];

  public function __construct(
    private readonly Streams $streams,
  ) {
  }

  public function add(int $fd): void
  {
    $this->watching[$fd] = true;
  }

  public function remove(int $fd): void
  {
    unset($this->watching[$fd]);
  }

  public function wait(?int $timeoutMs): array
  {
    $read = [];
    $write = [];
    foreach (array_keys($this->watching) as $id) {
      $stream = $this->streams->get($id);
      if ($stream === null) {
        continue;
      }
      if ($this->streams->isReadArmed($id)) {
        $read[$id] = $stream;
      }
      if ($this->streams->isWriteArmed($id)) {
        $write[$id] = $stream;
      }
    }

    // 空の配列だけを渡すと stream_select は ValueError になる
    if ($read === [] && $write === []) {
      if ($timeoutMs !== null) {
        usleep($timeoutMs * 1000);
      }

      return [];
    }

    $except = null;
    $seconds = $timeoutMs === null ? null : intdiv($timeoutMs, 1000);
    $microseconds = $timeoutMs === null ? null : ($timeoutMs % 1000) * 1000;
    // シグナルで中断されると false と警告になる
    if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) {
      return [];
    }

    // stream_select はキー (ID) を保ったまま、準備ができたものだけを残す
    $ids = array_unique([...array_keys($read), ...array_keys($write)]);
    $readiness = [];
    foreach ($ids as $id) {
      $readable = isset($read[$id]);
      $writable = isset($write[$id]);
      if ($readable) {
        $this->streams->disarmRead($id);
      }
      if ($writable) {
        $this->streams->disarmWrite($id);
      }
      $readiness[] = new Readiness($id, readable: $readable, writable: $writable);
    }

    return $readiness;
  }
}
