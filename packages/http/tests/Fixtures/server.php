<?php

declare(strict_types=1);

// HttpServerTest から別プロセスで起動する。フレームワークを使わない PSR-15 ハンドラを動かす

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Http\HttpServer;

$handler = new class () implements RequestHandlerInterface {
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return new Response(200, ['X-Handler' => 'plain-psr15'], "{$request->getMethod()} {$request->getUri()->getPath()}");
  }
};

(new HttpServer())->serve($handler, '127.0.0.1', 0);
