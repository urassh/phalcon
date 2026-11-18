<?php

declare(strict_types=1);

// PhalconRunTest から php -S のルータスクリプトとして起動する。サーバを登録せずに run() を呼ぶ

require __DIR__ . '/../../vendor/autoload.php';

use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new Phalcon();

$app->get('/users/{id}', fn (Request $req) => Response::json(['id' => $req->param('id')]));

$app->post('/memos', fn (Request $req) => Response::text("created {$req->form('title')}", 201));

$app->run();
