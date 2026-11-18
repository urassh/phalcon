# Phalcon

[![CI](https://github.com/urassh/phalcon/actions/workflows/ci.yml/badge.svg)](https://github.com/urassh/phalcon/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4)
![License](https://img.shields.io/badge/license-MIT-blue)

PHP だけで動く、小さな Web フレームワークと HTTP サーバ。
`php app.php` でそのまま立ち上がり、同じコードを PHP-FPM や Apache の上でも動かせる。

```php
$app = new Phalcon();

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

$app->run();
```

> C 拡張のフレームワーク [Phalcon](https://phalcon.io/) とは無関係のプロジェクト。

## 特徴

- **PHP だけで動く** … HTTP サーバも PHP で書いてある。Linux で FFI が使えれば epoll、それ以外は `stream_select` で接続を多重化する
- **どこでも `$app->run()`** … `php app.php` なら内蔵の HTTP サーバで常駐し、PHP-FPM や Apache から呼ばれれば 1 リクエストを処理する
- **PSR-7 / PSR-15 準拠** … ほかの PSR-15 対応サーバやミドルウェアと組み合わせられる
- **必要な層だけ使える** … フレームワーク、HTTP サーバ、PSR-7 実装、イベントループの 4 パッケージに分かれている

## はじめる

PHP 8.3 以上が必要。`ext-ffi` があれば epoll を使い、`ext-pcntl` と `ext-posix` があればワーカを複数にできる。

```sh
composer require urassh/phalcon-framework urassh/phalcon-http urassh/phalcon-server
```

```php
<?php
// app.php

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new Phalcon();

$app->get('/', fn (Request $req) => Response::text("Hello, world!\n"));

$app->run();
```

```sh
$ php app.php
Listening on 0.0.0.0:8080

$ curl http://localhost:8080/
Hello, world!
```

待ち受け先やワーカ数は、プロジェクトのルートに置いた `phalcon.yaml` で変えられる。
ルーティング、テンプレート、設定ファイル、PHP-FPM での動かし方は [phalcon-framework の README](packages/framework) にある。

## パッケージ

境界は PSR-7 / PSR-15 にそろえてある。

| パッケージ | 役割 |
|---|---|
| [urassh/phalcon-framework](packages/framework) | ルーティング、テンプレート、設定ファイル。`Phalcon` が PSR-15 のハンドラ |
| [urassh/phalcon-http](packages/http) | 任意の PSR-15 ハンドラを動かす HTTP/1.1 サーバ |
| [urassh/phalcon-core](packages/core) | PSR-7 / PSR-17 の実装と、SAPI (PHP-FPM など) 向けのリクエストの組み立てと応答の書き出し |
| [urassh/phalcon-server](packages/server) | プロトコルを問わないイベントループとソケット |

```
phalcon-framework ──┐
                    ├──> phalcon-core
phalcon-http ───────┘
     └╌╌> phalcon-server (任意。既定のサーバ層)
```

framework は http / server に依存せず、server は HTTP を知らない。依存の向きは `scripts/check-boundaries.sh` で CI から検査している。

## 開発

Issue と PR はこのリポジトリで受け付ける (各パッケージの split 先は読み取り専用)。開発は Docker 上で行う。

```sh
make build              # イメージをビルド
make install            # ルートと全パッケージで composer install
make test               # 全パッケージの PHPUnit (PKG=server で 1 パッケージだけ)
make analyse            # 全パッケージの PHPStan (level max)
make boundaries         # パッケージ間の依存の向きを検査
make smoke              # entrypoint.php を起動して curl を当てる
```

破壊的変更は [CHANGELOG.md](CHANGELOG.md) にまとめている。

## ライセンス

MIT
