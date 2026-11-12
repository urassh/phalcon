<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\App;
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new App();

$app->get('/', fn (Request $req) => Response::text("Hello, world!\n"));

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

$app->post('/echo', fn (Request $req) => Response::text($req->body()));

// App は PSR-15 のハンドラなので、起動は HttpServer に任せる
(new HttpServer())->serve($app, host: '0.0.0.0', port: 8080);
