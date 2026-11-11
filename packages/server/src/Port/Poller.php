<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

/**
 * エッジトリガで fd の状態変化を通知する
 *
 * 状態が変わったときにだけ通知するので、利用側は WouldBlock になるまで読み書きしきること
 *
 * @internal
 */
interface Poller
{
  /**
   * 読み込みと書き込みの両方を監視対象にする
   */
  public function add(int $fd): void;

  public function remove(int $fd): void;

  /**
   * 通知が来るか、タイムアウトするまで待つ。シグナルで中断された場合は空のリストを返す
   *
   * @param ?int $timeoutMs null なら無期限に待つ
   * @return list<Readiness>
   */
  public function wait(?int $timeoutMs): array;
}
