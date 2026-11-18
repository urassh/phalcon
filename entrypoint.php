<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Http\PhalconServerTransport;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new Phalcon();

$app->get('/', fn (Request $req) => Response::text("Hello, world!\n"));

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

$app->post('/echo', fn (Request $req) => Response::text($req->body()));

// 遅い処理の例。ブロックしている間、このワーカはほかの接続を処理できない
$app->get('/slow', function (Request $req) {
  sleep(2);

  return Response::text("slow\n");
});

// Phalcon は PSR-15 のハンドラなので、起動は HttpServer に任せる。待ち受け先とワーカ数はサーバ層 (Transport) に渡す。
// ワーカを 2 つにして、/slow の間もほかのワーカが応答できるようにする
(new HttpServer(new PhalconServerTransport(host: '0.0.0.0', port: 8080, workers: 2)))->serve($app);
