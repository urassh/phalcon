# Issue #3 実装計画: 4 パッケージ分割と PSR-7 / PSR-15 への境界統一

- Issue: https://github.com/urassh/phalcon/issues/3
- ブランチ: `feature/split-packages`

## 決定済み事項

| 項目 | 決定 |
|---|---|
| PSR-7 実装 | 自作する |
| HTTP 層 | `Psr\Http\Server\RequestHandlerInterface` を受け取る形に書き換える |
| フレームワーク層 | `App` を `RequestHandlerInterface` として実装する |
| リポジトリ構成 | モノレポ + split |
| 設定 | パッケージごとに composer.json / テスト / phpstan 設定を分ける |
| 起動 | 分割後も `entrypoint.php` から起動できること |
| 自作 PSR-7 の置き場所 | 専用パッケージ `phalcon-core` (Q1 案 A) |
| `App::serve()` | 削除し、起動は `phalcon-http` の `HttpServer` に任せる (Q2 案 A) |
| パッケージ構成 | `phalcon-server` (ループ + プラットフォーム) / `phalcon-http` (HTTP サーバ) / `phalcon-core` (PSR-7) / `phalcon-framework` (フレームワーク) の 4 つ (Q3) |
| `Internal` 名前空間 | `phalcon-server` / `phalcon-http` ではやめて公開範囲を絞る。フレームワークは残す (Q4) |

## 検討経緯 (Q1〜Q4、すべて決定済み)

### Q1. 自作 PSR-7 をどのパッケージに置くか → 決定: 案 A (`phalcon-core`)

HTTP サーバは `ServerRequestInterface` を作り、フレームワークは `ResponseInterface` を作る。両方に具象クラスが要るが、フレームワークは HTTP サーバに依存できない。

- **案 A (採用): 専用パッケージ `phalcon-core` を作る**。PSR-7 の実装と PSR-17 ファクトリを置き、HTTP サーバとフレームワークの両方がこれに依存する。`nyholm/psr7` の代わりに自作版を使う構図と同じ
- 案 B: HTTP サーバに置き、フレームワークは `psr/http-factory` (PSR-17) のインターフェースだけに依存してファクトリを注入する。パッケージは 3 つのままだが、`Response::text()` のような static コンストラクタからファクトリに触れないため、フレームワークの API が崩れる

### Q2. `App::serve()` をどうするか → 決定: 案 A

今の `serve()` は `EventLoop` と `PlatformFactory` を直接使っている。完了条件「フレームワークがループ / プラットフォームに直接依存しない」を満たすには、フレームワークから外す必要がある。

- **案 A (採用): `App::serve()` を削除し、起動は `phalcon-http` の `HttpServer` に任せる**。`entrypoint.php` は次のようになる

  ```php
  $app = new App();
  // ルート登録は今と同じ
  (new HttpServer())->serve($app, host: '0.0.0.0', port: 8080);
  ```

  設定ファイルの `server.host` / `server.port` は `$app->config('server.host')` で取り出して渡す
- 案 B: `serve()` を残し、`class_exists()` で HTTP サーバの有無を見て動的に呼ぶ。composer.json 上の依存はなくなるが、実質的には依存しているので完了条件の趣旨に反する

### Q3. パッケージ名と名前空間 → 決定

ループ + プラットフォーム (`phalcon-server`) と HTTP サーバ (`phalcon-http`) は別パッケージにする。

| パッケージ | Composer 名 | 名前空間 | split 先リポジトリ |
|---|---|---|---|
| ループ + プラットフォーム | `urassh/phalcon-server` | `Urassh\Phalcon\Server\` | `urassh/phalcon-server` |
| HTTP サーバ | `urassh/phalcon-http` | `Urassh\Phalcon\Http\` | `urassh/phalcon-http` |
| PSR-7 / PSR-17 実装 | `urassh/phalcon-core` | `Urassh\Phalcon\Core\` | `urassh/phalcon-core` |
| フレームワーク | `urassh/phalcon-framework` | `Urassh\Phalcon\` (今のまま) | `urassh/phalcon-framework` |

- 分けられる根拠: 今の `Internal/Server/**` は `Internal/Http/**` を一切参照していない。ループは `Protocol` (`isComplete` / `respond`) 越しにしかプロトコルを知らない。HTTP が混ざるのは `App::serve()` で `new HttpProtocol(...)` している 1 箇所だけ
- 起動処理を 2 段に分ける
  - `phalcon-server`: `Server::serve(Protocol $protocol, string $host, int $port): never`。PlatformFactory → listen → EventLoop。プロトコルを問わない
  - `phalcon-http`: `HttpServer::serve(RequestHandlerInterface $handler, string $host, int $port): never`。`new HttpProtocol($handler)` を作って `Server` に渡すだけ
- ループ / プラットフォームを `phalcon-core` に入れない理由: フレームワークが core に依存するので、ループ / プラットフォームへの依存が推移的に入り、完了条件に反する

フレームワークの Composer 名は `urassh/phalcon` から `urassh/phalcon-framework` に変える (破壊的変更。利用者は `composer require` し直す)。名前空間は `Urassh\Phalcon\` のまま残すので、既存利用者の `use` 文は変わらない。モノレポ本体 (`urassh/phalcon`) は開発用ルートとして残す。

### Q4. `Internal` 名前空間の扱い → 決定

`phalcon-server` と `phalcon-http` は、分割すると今 `Internal` にあるクラスが外から使われる側になる。

- **採用: `phalcon-server` と `phalcon-http` では `Internal` をやめ、公開するものを絞る**
  - `phalcon-server`: `Server`, `EventLoop`, `Protocol`, `Port\*` (Socket / ListeningSocket / Poller / Clock / Platform / Readiness / SocketException), `Platform\PlatformFactory`, `Platform\SystemClock` を公開。`Connection` とプラットフォーム実装 (`Linux\*`, `StreamSelect\*`) は `@internal` のまま
  - `phalcon-http`: `HttpServer` と `HttpProtocol` を公開。パーサ・シリアライザ・`Headers` は `@internal`
  - `phalcon-core`: 実装クラスと PSR-17 ファクトリを公開
- フレームワークは今のまま `Internal` を残す (`Routing`, `View`, `Config`)

## 目標の依存関係

```
phalcon-framework ────┐
                      ├──> phalcon-core ──> psr/http-message, psr/http-factory
phalcon-http ─────────┘
     │   └──> psr/http-server-handler
     └──> phalcon-server

phalcon-framework ──> psr/http-server-handler, psr/http-server-middleware, symfony/yaml
```

- フレームワーク → `phalcon-server` / `phalcon-http` の依存はない
- `phalcon-server` は HTTP を知らない (`Protocol` は「バイト列が揃ったか」「応答のバイト列」だけを扱う)

## モノレポ構成

```
phalcon/
├── composer.json          # 開発用ルート。path リポジトリで packages/* を読み込む
├── entrypoint.php         # パッケージを組み合わせて起動する
├── Makefile               # 全パッケージの test / analyse をまとめて回す
├── compose.yaml, docker/
├── .github/workflows/
│   ├── ci.yml             # パッケージごとに composer install → test → analyse (matrix)
│   └── split.yml          # main への push / tag で各パッケージを split 先へ push
└── packages/
    ├── server/     { composer.json, src/, tests/, phpunit.xml.dist, phpstan.neon.dist, README.md, LICENSE }
    ├── http/       { 同上 }
    ├── core/       { 同上 }
    └── framework/  { 同上 }
```

- 各パッケージの composer.json は兄弟パッケージを `"repositories": [{"type": "path", "url": "../core"}]` で参照し、パッケージのディレクトリ単体で `composer install && composer test` が通るようにする
- split は `splitsh-lite` か `symplify/monorepo-split-github-action` を使う。split 先は読み取り専用 (PR はモノレポで受ける)

## 現在のコードの移動先

| 現在 | 移動先 | 備考 |
|---|---|---|
| `Internal/Server/EventLoop.php`, `Connection.php`, `Protocol.php` | server | |
| `Internal/Server/Port/*` | server (`Port\`) | |
| `Internal/Server/Platform/*` | server (`Platform\`) | |
| `Internal/Http/Headers.php` | http | |
| `Internal/Http/HttpProtocol.php` | http | `RequestHandlerInterface` を受け取る形に変更 |
| `Request::parse()`, `splitHead()`, `HEADER_TERMINATOR` | http (`RequestParser`) | `ServerRequestInterface` を返す |
| `Response::toString()`, `REASON_PHRASES`, `MANAGED_HEADERS` | http (`ResponseSerializer`) | `ResponseInterface` を受け取る。理由句は PSR-7 実装側にも持たせる |
| `Internal/Http/RequestHandler.php` | 削除 | `RequestHandlerInterface` に置き換え |
| `Internal/Http/BadRequestException.php` | framework | `Request::json()` が投げ、`Router` が捕まえるので framework に閉じる |
| `App`, `Request`, `Response`, `Renderer`, `functions.php`, `Exception/*` | framework | |
| `Internal/Routing/*`, `Internal/View/*`, `Internal/Config/*` | framework | |
| `App::serve()` の中身 | server (`Server`) + http (`HttpServer`) | Q2。プロトコル非依存の起動は `Server`、`HttpProtocol` の組み立ては `HttpServer` |
| `tests/Internal/Server/**` (Fake 含む) | server | |
| `tests/Internal/Http/**` | http | |
| `tests/AppTest`, `RequestTest`, `ResponseTest`, `FunctionsTest`, `Internal/Config`, `Internal/View` | framework | パース経由のテストは PSR-7 経由に書き換える |

## フェーズ

1 フェーズ = 1 PR (または 1 コミット) を目安にする。各フェーズの終わりで `make test` と `make analyse` が通る状態を保つ。

### Phase 0: 下準備

- [ ] `psr/http-message` ^2.0, `psr/http-factory` ^1.1, `psr/http-server-handler` ^1.0, `psr/http-server-middleware` ^1.0 を追加
- [ ] 分割前の動作確認用に、`entrypoint.php` に curl を当てるスモークテスト (`GET /`, `GET /users/42`, `POST /echo`, 404, 405, 不正リクエストで 400) をスクリプトにしておく。以降のフェーズで毎回回す

### Phase 1: PSR-7 / PSR-17 の自作実装

まだ `src/` 内で作る (名前空間は最終形の `Urassh\Phalcon\Core\` にしておくと後の移動が楽)。

- [ ] `Stream` (`StreamInterface`): 文字列を持つインメモリ実装。リクエスト全体をバッファしてから処理する今の設計に合わせ、ストリーミングは対象外
- [ ] `Uri` (`UriInterface`): origin-form / absolute-form のリクエストターゲットから組み立てられること
- [ ] `Request`, `ServerRequest`, `Response` (`MessageInterface` の共通部分はトレイトか抽象クラスにまとめる)
  - ヘッダ名の大文字小文字を区別しない比較、元の綴りの保持
  - `withAttribute()` (ルートパラメータの受け渡しに使う)
  - 不変性 (`with*()` は必ず新しいインスタンスを返す)
- [ ] `UploadedFile` は `ServerRequestInterface` の型を満たすための最小実装にとどめる (multipart のパースはしない)
- [ ] PSR-17 ファクトリ: `Psr17Factory` 1 クラスで `ResponseFactoryInterface` / `ServerRequestFactoryInterface` / `StreamFactoryInterface` / `UriFactoryInterface` を実装
- [ ] テスト: 各 `with*()` の不変性、ヘッダの大文字小文字、不正値での `InvalidArgumentException`

### Phase 2: HTTP 層を PSR-7 / PSR-15 ベースにする

- [ ] `RequestParser`: 生のバイト列 → `ServerRequestInterface`。`Request::parse()` / `splitHead()` のロジックを移す。不正なリクエストラインは `InvalidArgumentException`
- [ ] `ResponseSerializer`: `ResponseInterface` → バイト列。`Content-Length` と `Connection: close` はサーバが付け、利用者の指定は無視する (今の `MANAGED_HEADERS` の挙動を維持)
- [ ] `HttpProtocol` のコンストラクタを `RequestHandlerInterface` に変える。`isComplete()` は `RequestParser` 側の関数を使う
- [ ] ハンドラが例外を投げたら 500 を返す (今は `Router` だけが捕まえているが、任意の PSR-15 ハンドラを受け付けるならサーバ側にも最後の砦が要る)
- [ ] `Server` クラス (server 側): `serve(Protocol $protocol, string $host, int $port): never`。今の `App::serve()` の中身 (PlatformFactory → listen → EventLoop) を移す
- [ ] `HttpServer` クラス (http 側): `serve(RequestHandlerInterface $handler, string $host, int $port): never`。`HttpProtocol` を組み立てて `Server` に渡す
- [ ] この時点では `App` 側に PSR-15 を実装するアダプタ (Phase 3 の先取り) を一時的に置くか、Phase 2 と 3 を同じ PR にする
- [ ] テスト: `HttpProtocolTest` をフレームワーク非依存にし、テスト用の小さな PSR-15 ハンドラ (固定レスポンスを返す無名クラス) で書き直す

### Phase 3: フレームワークを `RequestHandlerInterface` にする

- [ ] `App implements RequestHandlerInterface`。`handle(ServerRequestInterface): ResponseInterface`
- [ ] `Request` は `ServerRequestInterface` をラップする形にする。`param()` は `getAttribute()` から、`header()` / `query()` / `body()` / `json()` は PSR-7 のメソッドから取る。利用者向けのメソッドは変えない
- [ ] `Response` は今の API (`text` / `html` / `json` / `view` / `withStatus` / `withHeader`) を保ち、`Router` の出口で `ResponseInterface` に変換する (`toPsr(ResponseFactoryInterface)`)。View の描画は変換の前に済ませる
- [ ] `Router` は内部 API のまま。PSR 由来の `RequestHandler` 実装をやめる
- [ ] ミドルウェア: `App::add(MiddlewareInterface)` を追加し、`handle()` でミドルウェアのパイプラインを通してから `Router` に渡す。今回はパイプラインの仕組みだけで、標準ミドルウェアは作らない
- [ ] `App::serve()` を削除 (Q2 案 A の場合)。`App` から `Internal\Server\*` / `Internal\Http\*` の `use` が消えていることを確認
- [ ] テスト: `AppTest` は `ServerRequest` を直接組み立てて `handle()` を呼ぶ形に書き換え。`Request::parse()` 依存をなくす

### Phase 4: ディレクトリを packages/ に分ける

- [ ] `git mv` で `packages/{server,http,core,framework}/src` と `tests` に移す (履歴を追えるようにリネームだけのコミットを分ける)
- [ ] 名前空間を書き換える (`Urassh\Phalcon\Internal\Server\` → `Urassh\Phalcon\Server\`、`Internal\Http\` → `Urassh\Phalcon\Http\` など)
- [ ] 各パッケージに composer.json / phpunit.xml.dist / phpstan.neon.dist (level max) / README.md / LICENSE を置く
- [ ] ルート composer.json を開発用に作り直す (path リポジトリで 4 パッケージを require)
- [ ] 各パッケージのディレクトリで単体に `composer install && composer test && composer analyse` が通ることを確認

### Phase 5: 起動・開発環境・CI

- [ ] `entrypoint.php` を新しい構成で書き直す (Q2)
- [ ] `Makefile` の `test` / `analyse` を全パッケージに回す形にする (`make test PKG=server` で個別にも回せるように)
- [ ] `compose.yaml` / Dockerfile はそのまま使えるはず。ボリュームのマウント位置だけ確認
- [ ] `.github/workflows/ci.yml`: パッケージを matrix にして、それぞれのディレクトリで install → test → analyse
- [ ] `.github/workflows/split.yml`: split 先リポジトリを用意してから有効化する (先にリポジトリ作成と deploy key / token の設定が要る)
- [ ] README: ルートは全体の説明とパッケージの一覧に、各パッケージにそれぞれの使い方を書く。`serve()` の削除などの破壊的変更を明記

### Phase 6: 完了条件の確認

- [ ] 4 パッケージがそれぞれ単体で `composer install` / `composer test` / `composer analyse` を通る
- [ ] HTTP サーバで任意の PSR-15 ハンドラを動かせる: フレームワーク以外のハンドラ (テスト用の無名クラス) を `HttpServer` に渡して起動し、curl で応答を確認するテストを http パッケージに置く
- [ ] フレームワークがループ / プラットフォームに依存していない: `packages/framework/composer.json` に `phalcon-server` / `phalcon-http` がないこと、`packages/framework/src` に `Urassh\Phalcon\Server\` / `Urassh\Phalcon\Http\` の参照がないこと (CI で grep するか、phpstan のルールか deptrac で検査)
- [ ] `phalcon-server` が HTTP を知らない: `packages/server/composer.json` に `phalcon-http` / `psr/http-*` がなく、`packages/server/src` に `Urassh\Phalcon\Http\` の参照がないこと
- [ ] Phase 0 のスモークテストが分割前と同じ結果になる

## リスク・注意点

- **破壊的変更**: フレームワークの Composer 名の変更 (`urassh/phalcon` → `urassh/phalcon-framework`)、`App::serve()` の削除 (Q2 案 A)、`Request::parse()` など `@internal` メソッドの移動。バージョンは 0.x なのでマイナーを上げて README に移行方法を書く
- **パース経由のテスト**: `AppTest` などが `Request::parse()` で文字列からリクエストを作っているので、Phase 3 で全面的に書き換えが要る
- **PSR-7 の自作範囲**: 仕様を全部満たそうとすると重い (`UploadedFile` や `getParsedBody()` の扱いなど)。今回は「型を満たす最小限 + 今の機能で使うところは正しく」に絞り、足りない部分は README に書く
- **ヘッダの扱いの差**: 今の `Headers` は値を `trim` してリストで持ち、`Response` は 1 ヘッダ 1 値。PSR-7 は複数値前提なので、シリアライズ時に複数値をどう出すか (`Set-Cookie` は行を分け、それ以外はカンマ結合) を決めておく
- **split の運用**: タグはモノレポで打ち、split 時に各リポジトリへ同じタグを付ける。パッケージ間のバージョン制約はそろえて上げる
