# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 概要

PHP の標準関数 (`stream_socket_server`) と FFI (epoll) の上に組み立てた小さな HTTP サーバと Web フレームワークのモノレポ。`packages/` 以下の 4 パッケージに分かれ、境界は PSR-7 / PSR-15 にそろえてある。各パッケージは `.github/workflows/split.yml` で `urassh/phalcon-{server,core,http,framework}` に split される (split 先は読み取り専用)。

## コマンド

開発は Docker (PHP 8.3 + FFI + Composer) 上で行う。`make` の各ターゲットは `docker compose run --rm php` 経由で動く。

```sh
make build              # イメージをビルド
make install            # ルートと全パッケージで composer install
make test               # 全パッケージの PHPUnit
make test PKG=server    # 1 パッケージだけ (PKG は server / core / http / framework、空白区切りで複数可)
make analyse            # 全パッケージの PHPStan (level max, src と tests が対象)
make boundaries         # パッケージ間の依存の向きを検査 (scripts/check-boundaries.sh)
make smoke              # entrypoint.php を起動して curl を当てる (scripts/smoke.sh)
make shell              # コンテナに入る
```

単一のテストクラス / メソッドを回すときは、パッケージのディレクトリで phpunit を直接呼ぶ:

```sh
docker compose run --rm php sh -c 'cd packages/http && vendor/bin/phpunit --filter RequestParserTest'
docker compose run --rm php sh -c 'cd packages/http && vendor/bin/phpunit --filter "RequestParserTest::testXxx"'
```

CI (`.github/workflows/ci.yml`) はパッケージごとに単体で `composer install && composer test && composer analyse` を回し、別ジョブで boundaries と smoke を回す。ローカルで変更したパッケージも単体でこれが通る状態を保つこと。

## アーキテクチャ

### パッケージと依存の向き

| パッケージ | 名前空間 | 役割 |
|---|---|---|
| `packages/server` (phalcon-server) | `Urassh\Phalcon\Server\` | プロトコル非依存のイベントループとソケットのプラットフォーム。外部依存なし |
| `packages/core` (phalcon-core) | `Urassh\Phalcon\Core\` | 自作の PSR-7 / PSR-17 実装 |
| `packages/http` (phalcon-http) | `Urassh\Phalcon\Http\` | 任意の PSR-15 ハンドラを動かす HTTP/1.1 サーバ。server + core に依存 |
| `packages/framework` (phalcon-framework) | `Urassh\Phalcon\` | ルーティング、テンプレート、YAML 設定。`App` が PSR-15 ハンドラ。core に依存 |

`scripts/check-boundaries.sh` が grep で以下を強制している (CI で落ちる):

- framework は server / http に依存しない (composer.json でも `src` の名前空間参照でも)
- server は HTTP を知らない (`urassh/phalcon-http`、`psr/http-*`、`Psr\Http\` を参照しない)
- core は server / http に依存しない

新しいコードをどのパッケージに置くかはこの制約から決まる。たとえば framework から HTTP のパースやシリアライズに触れることはできず、そこは http パッケージの責務。

### リクエストの流れ

1. `Server\Server::serve(Protocol, host, port)` が `PlatformFactory::detect()` でプラットフォームを選ぶ。Linux かつ FFI が使えれば `Platform\Linux` (epoll、`Libc` で libc を FFI ロード)、それ以外 (macOS ホストで直接動かす場合など) は `Platform\StreamSelect`
2. `EventLoop` が接続ごとの `Connection` にバイト列をためる。ループは `Server\Protocol` インターフェース (`isComplete(string)` / `respond(string): string`) 越しにしかプロトコルを知らない
3. `Http\HttpProtocol` が `Protocol` を実装し、`RequestParser` で core の `ServerRequest` を作って PSR-15 ハンドラに渡し、`ResponseSerializer` でバイト列に戻す。パース失敗は 400、ハンドラの例外は 500 にここで変換する
4. framework の `App::handle()` はミドルウェアの `Internal\Middleware\Pipeline` を通して `Internal\Routing\RouterHandler` に渡す。RouterHandler が PSR-7 リクエストをフレームワーク独自の `Request` に包み、`Router` の結果 (`Response`) を `toPsr()` で PSR-7 に戻す

`entrypoint.php` は `App` と `HttpServer` を組み合わせるサンプルで、`make smoke` の対象。smoke は `GET /`・`/users/{id}`・`POST /echo`・404・405・400 を確かめているので、ルーティングやエラー応答を変えたら `scripts/smoke.sh` も合わせる。

### フレームワーク層の公開範囲

- ユーザ向け API は `App`、`Request`、`Response`、`Renderer`、`functions.php` (`autoload.files`)。ハンドラは `callable(Request): Response` で、PSR-7 は直接見せない
- `Internal\` 名前空間 (Routing / Middleware / Config / View / Http) と `@internal` メソッドは非公開扱い。server / http パッケージでは `Internal` 名前空間を使わず、公開範囲はクラスの粒度で絞る方針 (経緯は `docs/issue-3-plan.md`)
- `Response::view()` は未描画のテンプレートを保持し、`Renderer` (既定は `Internal\View\PhpRenderer`) で描画される。`App::fromConfig()` が YAML の view ディレクトリから renderer を設定する

### テスト

server のテストは `tests/Fake*.php` (FakeSocket / FakePoller / FakeClock / FakeProtocol など) で `Port\` のインターフェースを差し替え、実ソケットなしでループを検証する。プラットフォーム固有の実装を足すときは `Port\` のインターフェース (`Platform` / `Poller` / `Socket` / `ListeningSocket` / `Clock`) に合わせる。

## 規約

- コードは `declare(strict_types=1)`、インデント 2 スペース、コメントと PHPDoc は日本語
- PHPStan は level max。配列の形などは PHPDoc の型 (`list<...>`、`array{...}`) で明示する
- ルートの `composer.json` は開発用で、path リポジトリで `packages/*` を `0.2.x-dev` として読み込む。パッケージ間のバージョン制約はそろえて上げ、タグはモノレポで打つ
- Issue と PR はこのモノレポで受ける
