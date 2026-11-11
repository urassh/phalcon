# urassh/phalcon

PHP の標準関数 (`stream_socket_server`) と FFI (epoll) の上に組み立てた小さな HTTP サーバと Web フレームワークのモノレポ。
境界は PSR-7 / PSR-15 にそろえてあり、4 つのパッケージに分かれている。

| パッケージ | 役割 | 依存 |
|---|---|---|
| [urassh/phalcon-server](packages/server) | イベントループとソケットのプラットフォーム (epoll / stream_select)。プロトコルを問わない | なし |
| [urassh/phalcon-core](packages/core) | PSR-7 / PSR-17 の実装 | psr/http-message, psr/http-factory |
| [urassh/phalcon-http](packages/http) | 任意の PSR-15 ハンドラを動かす HTTP/1.1 サーバ | phalcon-server, phalcon-core, psr/http-server-handler |
| [urassh/phalcon-framework](packages/framework) | ルーティング、テンプレート、設定ファイル。`App` が PSR-15 のハンドラ | phalcon-core, psr/http-server-*, symfony/yaml |

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

## 使い方

```sh
composer require urassh/phalcon-framework urassh/phalcon-http
```

```php
use Urassh\Phalcon\App;
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new App();

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

(new HttpServer())->serve($app, host: '0.0.0.0', port: 8080);
```

詳しくは各パッケージの README を参照。

## 0.1 からの破壊的変更

- フレームワークのパッケージ名を `urassh/phalcon` から `urassh/phalcon-framework` に変えた (名前空間は `Urassh\Phalcon\` のまま)。`composer require` し直す
- `App::serve()` を削除した。起動は `urassh/phalcon-http` の `HttpServer` に任せる
- `App::handle()` は PSR-7 の `ServerRequestInterface` を受け取り `ResponseInterface` を返す
- `Request::parse()` や `Response::toString()` などの `@internal` メソッドを削除した (パースとシリアライズは phalcon-http に移った)

## 開発環境

Docker 上で PHP 8.3 + Composer を使う。ルートの composer.json は開発用で、path リポジトリで `packages/*` を読み込む。

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

## リポジトリ構成

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

各パッケージは `split.yml` で `urassh/phalcon-{server,core,http,framework}` に split する。split 先は読み取り専用で、Issue と PR はこのリポジトリで受ける。
タグはモノレポで打ち、split 時に各リポジトリにも同じタグを付ける。パッケージ間のバージョン制約はそろえて上げる。
