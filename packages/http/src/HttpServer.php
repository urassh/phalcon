<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Server\Server;

/**
 * PSR-15 のハンドラを HTTP サーバとして動かす
 */
final class HttpServer
{
  public function __construct(
    private readonly Server $server = new Server(),
  ) {
  }

  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   *
   * @param int $workers 2 以上ならその数だけワーカを fork する (pcntl と posix が必要)。ワーカ間でメモリ上の状態は共有されない
   */
  public function serve(RequestHandlerInterface $handler, string $host = '0.0.0.0', int $port = 8080, int $workers = 1): never
  {
    $this->server->serve(new HttpProtocol($handler), $host, $port, $workers);
  }
}
