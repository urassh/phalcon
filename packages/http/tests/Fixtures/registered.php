<?php

declare(strict_types=1);

// RegisterTest から別プロセスで起動する。DefaultRunner に登録された HttpServer を、渡した設定で起動する

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Core\Response;

$handler = new class () implements RequestHandlerInterface {
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return new Response(200, [], 'registered');
  }
};

DefaultRunner::create(['host' => '127.0.0.1', 'port' => 0, 'workers' => 2])?->run($handler);
