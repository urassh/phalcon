<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

use InvalidArgumentException;
use RuntimeException;
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
   *
   * @param int $workers 2 以上ならその数だけワーカを fork し、SO_REUSEPORT で接続を振り分ける
   * @throws InvalidArgumentException $workers が 1 未満
   * @throws RuntimeException $workers が 2 以上で pcntl / posix がない
   */
  public function serve(Protocol $protocol, string $host, int $port, int $workers = 1): never
  {
    if ($workers < 1) {
      throw new InvalidArgumentException("workers must be at least 1, got {$workers}");
    }

    if ($workers > 1) {
      // 単一プロセスに黙って倒すと、設定ミスに気づけない
      if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
        throw new RuntimeException('Running multiple workers requires the pcntl and posix extensions');
      }

      (new Supervisor($this->platform, $protocol, $host, $port, $workers))->run();
    }

    $listener = $this->platform->listen($host, $port);

    echo "Listening on {$listener->localAddress()}\n";

    $loop = new EventLoop($listener, $this->platform->createPoller(), $protocol, new SystemClock());
    $loop->run();
  }
}
