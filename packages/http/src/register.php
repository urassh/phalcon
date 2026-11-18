<?php

declare(strict_types=1);

// Composer の autoload.files で読み込まれる。phalcon-http をインストールしただけで、
// phalcon-framework の Phalcon::run() が CLI から HttpServer で起動できるようにする

use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Http\PhalconServerTransport;

DefaultRunner::register(
  /**
   * @param array{host?: string, port?: int, workers?: int} $options
   */
  static function (array $options): HttpServer {
    // phalcon-server がなければ Transport を渡さず、HttpServer に「サーバ層がない」例外を投げさせる
    if (!PhalconServerTransport::isAvailable()) {
      return new HttpServer();
    }

    // キーは PhalconServerTransport の引数名と同じなので名前付き引数で渡し、ないものは既定値にする
    return new HttpServer(new PhalconServerTransport(...$options));
  },
);
