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
  /**
   * @param int $workers 2 以上ならその数だけワーカを fork する。ワーカ間でメモリ上の状態は共有されない
   */
  public function __construct(
    private readonly string $host = '0.0.0.0',
    private readonly int $port = 8080,
    private readonly int $workers = 1,
    private readonly Server $server = new Server(),
  ) {
  }

  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   */
  public function serve(RequestHandlerInterface $handler): never
  {
    $this->server->serve(new HttpProtocol($handler), $this->host, $this->port, $this->workers);
  }
}
