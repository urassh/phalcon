<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use LogicException;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 のハンドラを HTTP サーバとして動かす
 */
final class HttpServer
{
  private readonly Transport $transport;

  /**
   * @param Transport|null $transport 待ち受け先などはサーバ層に渡す。省略すると urassh/phalcon-server で 0.0.0.0:8080 を待ち受ける
   * @throws LogicException $transport を省略し、urassh/phalcon-server もインストールされていない
   */
  public function __construct(?Transport $transport = null)
  {
    $this->transport = $transport ?? self::defaultTransport();
  }

  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   */
  public function serve(RequestHandlerInterface $handler): never
  {
    $this->transport->serve(new HttpProtocol($handler));
  }

  private static function defaultTransport(): Transport
  {
    if (!PhalconServerTransport::isAvailable()) {
      throw new LogicException(
        'No server layer is configured: install urassh/phalcon-server or pass a Transport to HttpServer',
      );
    }

    return new PhalconServerTransport();
  }
}
