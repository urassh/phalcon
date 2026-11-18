<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Urassh\Phalcon\Server\Protocol;
use Urassh\Phalcon\Server\Server;

/**
 * urassh/phalcon-server の Server をサーバ層として使う
 *
 * phalcon-server は任意の依存なので、`Urassh\Phalcon\Server\` を参照するのはこのクラスだけにする。
 * ほかのクラスが参照すると、phalcon-server を入れていない環境でそのクラスを読み込めなくなる
 */
final class PhalconServerTransport implements Transport
{
  /**
   * @param int $workers 2 以上ならその数だけワーカを fork する。ワーカ間でメモリ上の状態は共有されない
   * @param Server|null $server 省略すると serve() のときに実行環境に合わせて作る
   */
  public function __construct(
    private readonly string $host = '0.0.0.0',
    private readonly int $port = 8080,
    private readonly int $workers = 1,
    private readonly ?Server $server = null,
  ) {
  }

  /**
   * urassh/phalcon-server がインストールされているか
   */
  public static function isAvailable(): bool
  {
    return class_exists(Server::class);
  }

  public function serve(HttpProtocol $protocol): never
  {
    $adapter = new class ($protocol) implements Protocol {
      public function __construct(
        private readonly HttpProtocol $protocol,
      ) {
      }

      public function isComplete(string $data): bool
      {
        return $this->protocol->isComplete($data);
      }

      public function respond(string $data): string
      {
        return $this->protocol->respond($data);
      }
    };

    ($this->server ?? new Server())->serve($adapter, $this->host, $this->port, $this->workers);
  }
}
