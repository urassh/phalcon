# Phalcon

[![CI](https://github.com/urassh/phalcon/actions/workflows/ci.yml/badge.svg)](https://github.com/urassh/phalcon/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4)
![License](https://img.shields.io/badge/license-MIT-blue)

PHP だけで動く、小さな Web フレームワークと HTTP サーバ。
nginx や PHP-FPM を用意しなくても、`php app.php` でそのままアプリが立ち上がる。

```php
$app = new Phalcon();

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

(new HttpServer(port: 8080))->serve($app);
```

> C 拡張のフレームワーク [Phalcon](https://phalcon.io/) とは無関係のプロジェクト。

## 特徴

- **PHP だけで動く** … HTTP サーバも PHP で書いてあり、追加の C 拡張や外部サーバは要らない。Linux で FFI が使えれば epoll、それ以外は `stream_select` で接続を多重化する
- **書くのはハンドラだけ** … `Request` を受け取って `Response` を返す関数を、パスとメソッドに対応づけて登録する
- **PSR-7 / PSR-15 準拠** … `Phalcon` は PSR-15 の `RequestHandlerInterface` なので、ほかの PSR-15 対応サーバでも動く。ミドルウェアも PSR-15 のものをそのまま使える
- **マルチプロセス** … `workers` を指定するとワーカを fork し、複数のリクエストを並行に処理する
- **必要な層だけ使える** … フレームワーク、HTTP サーバ、PSR-7 実装、イベントループの 4 パッケージに分かれていて、それぞれ単体でも使える

## 要件

- PHP 8.3 以上
- 任意: `ext-ffi` (Linux で epoll を使う)
- 任意: `ext-pcntl` と `ext-posix` (`workers` を 2 以上にする)

## インストール

```sh
composer require urassh/phalcon-framework urassh/phalcon-http
```

## クイックスタート

`app.php` を作る。

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new Phalcon();

$app->get('/', fn (Request $req) => Response::text("Hello, world!\n"));

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

$app->post('/echo', fn (Request $req) => Response::text($req->body()));

(new HttpServer(host: '0.0.0.0', port: 8080))->serve($app);
```

起動してリクエストを送る。

```sh
$ php app.php
Listening on 0.0.0.0:8080

$ curl http://localhost:8080/users/42
{"id":"42"}

$ curl -X POST -d 'ping' http://localhost:8080/echo
ping
```

## 使い方

ここでは主な機能をざっと紹介する。API の一覧は [phalcon-framework の README](packages/framework) にある。

### ルーティング

```php
$app
  ->get('/users', $listUsers)
  ->post('/users', $createUser)
  ->put('/users/{id}', $updateUser)
  ->delete('/users/{id}', $deleteUser);
```

- `{name}` は 1 セグメントにマッチし、`$req->param('name')` で取り出せる
- 複数のルートにマッチするときは先に登録したほうが優先される
- ルートがなければ `404`、パスは合うがメソッドが違えば `405` (`Allow` ヘッダ付き) を返す

ハンドラにはクロージャのほか、`__invoke` を持つクラスや `[$controller, 'method']` も渡せる。

`Controller` を継承したクラスの `index` / `new` / `create` / `show` / `edit` / `update` / `destroy` をまとめて登録することもできる。

```php
$app->resources('/users', new UsersController()); // GET /users → index、GET /users/{id} → show など
$app->resources('/posts', new PostsController(), only: ['index', 'show']);
```

### リクエストとレスポンス

```php
$app->post('/users', function (Request $req) {
  $body = $req->json();          // 不正な JSON なら 400 を返して打ち切る
  $sort = $req->query('sort');   // ?sort=... がなければ null
  $token = $req->header('Authorization');

  return Response::created('/users/42', ['name' => $body['name']]);
});
```

`Response` には `text()` / `html()` / `json()` / `view()` / `redirect()` / `seeOther()` / `back()` / `created()` / `noContent()` がある。ハンドラが例外を投げると `500` を返し、例外はレスポンスに含めずログに出す。
`NotFoundException` などの HTTP の例外を投げるとそのステータスを返し、view ディレクトリに `errors/404.php` のようなテンプレートがあればそれで描画する。`Accept: application/json` のリクエストには `{"error": メッセージ}` の JSON を返し、形式は `$app->onError()` で変えられる。

### テンプレート

```php
$app->get('/hello/{name}', fn (Request $req) => Response::view('hello', [
  'name' => $req->param('name'),
]));
```

```php
<!-- views/hello.php -->
<h1>Hello, <?= e($name) ?></h1>
```

テンプレートは素の PHP ファイルで、`e()` で HTML エスケープする。アプリ名などすべてのテンプレート (エラーページを含む) で使う値は `$app->share('appName', ...)` で登録できる。Twig などに差し替えるときは `Renderer` を実装して `$app->useRenderer()` に渡す。

### ミドルウェア

PSR-15 の `MiddlewareInterface` をそのまま追加できる。先に追加したものほど外側で動く。

```php
$app->add(new class () implements MiddlewareInterface {
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    return $handler->handle($request)->withHeader('X-Powered-By', 'phalcon');
  }
});
```

CSRF 対策は組み込みで常に有効。別のサイトのページから送られた POST / PUT / PATCH / DELETE は、ブラウザが付ける `Sec-Fetch-Site` ヘッダを見て `403` にする。トークンをフォームに埋め込む必要はない。詳しくは [framework の README](packages/framework/README.md#csrf-対策) を参照。

### 設定ファイル

ポートやテンプレートのディレクトリ、DB の接続情報などは YAML にまとめて書ける。`${NAME}` は環境変数に置き換わる。

```yaml
# phalcon.yaml
server:
  host: 0.0.0.0
  port: 8080
  workers: 4

view:
  directory: views

database:
  dsn: mysql:host=db;dbname=app
  password: ${DB_PASSWORD}
```

```php
$app = Phalcon::fromConfig(__DIR__ . '/phalcon.yaml');

$dsn = $app->config('database.dsn');

(new HttpServer(
  host: $app->config('server.host'),
  port: $app->config('server.port'),
  workers: $app->config('server.workers'),
))->serve($app);
```

DB と組み合わせる例や、常駐プロセスで気をつけることも [phalcon-framework の README](packages/framework#db-と組み合わせる) にまとめてある。

## 並行処理のモデル

ハンドラは 1 プロセスの中で直列に呼ばれる。`sleep()` や遅い DB クエリ、外部 API 呼び出しの間は、そのプロセス全体が止まる。
並行に処理したいときは `workers` を増やす。その数だけワーカプロセスを fork し、同時にワーカ数までのリクエストを処理できる。

```php
(new HttpServer(host: '0.0.0.0', port: 8080, workers: 4))->serve($app);
```

- 各ワーカは `SO_REUSEPORT` 付きで同じアドレスに listen し、接続の振り分けはカーネルに任せる
- ワーカ間でメモリ上の状態 (static 変数やオブジェクトのプロパティ) は共有されない。起動前に組み立てた `Phalcon` や PDO などは fork でワーカごとにコピーされる
- 振り分けは接続の 4-tuple のハッシュで決まる。ブロック中のワーカに当たった接続は、そのワーカが戻るまで待たされる。空いているワーカが代わりに拾うことはない
- ワーカが落ちると master が同じ番号のワーカを起動し直す。落ちたワーカの accept キューに溜まっていた接続は切れる。起動直後の終了が 5 回続くと、設定ミスとみなして master も終了する
- master に SIGTERM / SIGINT を送ると、全ワーカを止めてから終了する。master を SIGKILL で止めるとワーカが残るので注意
- 接続ごとの 5 秒の期限は accept してから数える。ハンドラがブロックしている間に accept 済みの接続は期限切れで閉じられるが、カーネルの backlog にいる接続は数えられず、ブロックが終わって accept されてから新しく 5 秒をもらう
- macOS などの `stream_select` 環境でも `SO_REUSEPORT` で複数のワーカが listen できるが、接続が各ワーカに振り分けられるかは OS 次第 (1 つのワーカに集中しうる)
- Fiber による並行化は未対応。非同期版の sleep / ソケット / DB クライアントが揃ったときの選択肢として残している

## 制限

小さな実装なので、次の機能はない。

- keep-alive (1 リクエストごとに接続を閉じる)
- HTTPS
- chunked transfer encoding
- multipart/form-data のパース
- 1 MiB を超えるリクエスト

本番で使うなら、HTTPS の終端などはリバースプロキシに任せる。

## パッケージ

4 つのパッケージに分かれていて、境界は PSR-7 / PSR-15 にそろえてある。Web アプリを書くだけなら、上の 2 つを入れれば足りる。

| パッケージ | 役割 | こんなときに |
|---|---|---|
| [urassh/phalcon-framework](packages/framework) | ルーティング、テンプレート、設定ファイル。`Phalcon` が PSR-15 のハンドラ | Web アプリを書く |
| [urassh/phalcon-http](packages/http) | 任意の PSR-15 ハンドラを動かす HTTP/1.1 サーバ | 自前の PSR-15 ハンドラを動かす |
| [urassh/phalcon-core](packages/core) | PSR-7 / PSR-17 の実装 | 軽量な PSR-7 実装だけ欲しい |
| [urassh/phalcon-server](packages/server) | プロトコルを問わないイベントループとソケット (epoll / stream_select) | HTTP 以外のプロトコルのサーバを書く |

```
phalcon-framework ────┐
                      ├──> phalcon-core ──> psr/http-message, psr/http-factory
phalcon-http ─────────┘
     │   └──> psr/http-server-handler
     └──> phalcon-server
```

- フレームワークはループ / プラットフォーム (phalcon-server) にも HTTP サーバ (phalcon-http) にも依存しない
- phalcon-server は HTTP を知らない。`Protocol` (「バイト列が揃ったか」「応答のバイト列」) 越しにしかプロトコルを扱わない
- 依存の向きは `scripts/check-boundaries.sh` で CI から検査している

## アップグレード

### 次のリリースでの破壊的変更

- framework の `App` を `Phalcon` に改名した (`Urassh\Phalcon\Phalcon`)
- `HttpServer` の `host` / `port` / `workers` を `serve()` の引数からコンストラクタの引数に移した。`(new HttpServer(host: '0.0.0.0', port: 8080))->serve($app)` のように書く
- `Port\Platform::listen()` に `bool $reusePort = false` を足した。`Platform` を自前で実装している場合はシグネチャを合わせる

### 0.1 からの破壊的変更

- フレームワークのパッケージ名を `urassh/phalcon` から `urassh/phalcon-framework` に変えた (名前空間は `Urassh\Phalcon\` のまま)。`composer require` し直す
- `App::serve()` を削除した。起動は `urassh/phalcon-http` の `HttpServer` に任せる
- `App::handle()` は PSR-7 の `ServerRequestInterface` を受け取り `ResponseInterface` を返す
- `Request::parse()` や `Response::toString()` などの `@internal` メソッドを削除した (パースとシリアライズは phalcon-http に移った)

## 開発に参加する

Issue と PR はこのリポジトリで受け付ける。各パッケージの split 先 (`urassh/phalcon-{server,core,http,framework}`) は読み取り専用。

開発は Docker 上の PHP 8.3 + Composer で行う。ルートの composer.json は開発用で、path リポジトリで `packages/*` を読み込む。

```sh
make build              # イメージをビルド
make install            # ルートと全パッケージで composer install
make test               # 全パッケージの PHPUnit
make test PKG=server    # 1 パッケージだけ
make analyse            # 全パッケージの PHPStan (level max)
make boundaries         # パッケージ間の依存の向きを検査
make smoke              # entrypoint.php を起動して curl を当てる
make shell              # コンテナに入る
```

各パッケージはそれぞれのディレクトリで単体に `composer install && composer test && composer analyse` が通る。

```
packages/
  server/       urassh/phalcon-server     (Urassh\Phalcon\Server\)
  core/         urassh/phalcon-core       (Urassh\Phalcon\Core\)
  http/         urassh/phalcon-http       (Urassh\Phalcon\Http\)
  framework/    urassh/phalcon-framework  (Urassh\Phalcon\)
entrypoint.php  パッケージを組み合わせて起動するサンプル
scripts/        スモークテスト、依存の検査
docker/php/     開発用 PHP イメージ
.github/workflows/
  ci.yml        パッケージごとに install → test → analyse
  split.yml     main への push / タグで各パッケージを split 先へ push (要設定)
```

タグはモノレポで打ち、split 時に各リポジトリにも同じタグを付ける。パッケージ間のバージョン制約はそろえて上げる。

## ライセンス

MIT
