<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

/**
 * 接続から受け取ったバイト列をどう扱うか。ループはこれ越しにしかプロトコルを知らない
 */
interface Protocol
{
  /**
   * 応答を作れるだけのデータが揃ったか
   */
  public function isComplete(string $data): bool;

  /**
   * 揃ったデータに対する応答のバイト列を返す
   */
  public function respond(string $data): string;
}
