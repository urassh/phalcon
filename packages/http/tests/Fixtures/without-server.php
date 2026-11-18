<?php

declare(strict_types=1);

// HttpServerTransportTest から別プロセスで起動する。urassh/phalcon-server がインストールされていない環境を再現する

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../../vendor/autoload.php';

// Composer のオートローダを外し、Urassh\Phalcon\Server\ だけ読み込めないものに差し替える
$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader): void {
  if (!str_starts_with($class, 'Urassh\\Phalcon\\Server\\')) {
    $loader->loadClass($class);
  }
});

use Urassh\Phalcon\Http\HttpProtocol;
use Urassh\Phalcon\Http\HttpServer;

// サーバ層を差し替えて使えるよう、HttpProtocol は phalcon-server なしで読み込める
echo class_exists(HttpProtocol::class) ? "HttpProtocol loaded\n" : "HttpProtocol missing\n";

try {
  new HttpServer();
  echo "no exception\n";
} catch (LogicException $e) {
  echo $e->getMessage(), "\n";
}
