<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

use Urassh\Phalcon\Server\Platform\PlatformFactory;
use Urassh\Phalcon\Server\Platform\SystemClock;
use Urassh\Phalcon\Server\Port\Platform;

/**
 * 待ち受けを始めてイベントループを回す。プロトコルは問わない
 */
final class Server
{
  private readonly Platform $platform;

  /**
   * @param Platform|null $platform 省略すると実行環境に合わせて選ぶ
   */
  public function __construct(?Platform $platform = null)
  {
    $this->platform = $platform ?? PlatformFactory::detect();
  }

  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   */
  public function serve(Protocol $protocol, string $host, int $port): never
  {
    $listener = $this->platform->listen($host, $port);

    echo "Listening on {$listener->localAddress()}\n";

    $loop = new EventLoop($listener, $this->platform->createPoller(), $protocol, new SystemClock());
    $loop->run();
  }
}
