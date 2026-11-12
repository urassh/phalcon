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
   */
  public function serve(RequestHandlerInterface $handler, string $host = '0.0.0.0', int $port = 8080): never
  {
    $this->server->serve(new HttpProtocol($handler), $host, $port);
  }
}
