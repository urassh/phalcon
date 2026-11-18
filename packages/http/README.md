# urassh/phalcon-http

任意の PSR-15 ハンドラ (`Psr\Http\Server\RequestHandlerInterface`) を動かす HTTP/1.1 サーバ。
PSR-7 の実装は [urassh/phalcon-core](../core) を使う。待ち受けと接続の読み書き (サーバ層) は既定で [urassh/phalcon-server](../server) に任せ、`Transport` を実装すれば差し替えられる。

> このリポジトリは [urassh/phalcon](https://github.com/urassh/phalcon) モノレポから split した読み取り専用のコピー。Issue と PR はモノレポで受け付ける。

## インストール

```sh
composer require urassh/phalcon-http urassh/phalcon-server
```

`urassh/phalcon-server` は既定のサーバ層で、require ではなく suggest にしてある。入れずに `Transport` を渡さないと、`HttpServer` の生成時に `LogicException` になる。

## 使い方

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Http\PhalconServerTransport;

$handler = new class () implements RequestHandlerInterface {
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return new Response(200, ['Content-Type' => 'text/plain'], "Hello, {$request->getUri()->getPath()}\n");
  }
};

(new HttpServer(new PhalconServerTransport(host: '0.0.0.0', port: 8080)))->run($handler);
```

[urassh/phalcon-framework](../framework) の `Phalcon` では、phalcon-http をインストールしてあれば CLI から `$app->run()` するだけで `new HttpServer()` で起動する。phalcon-http は Composer の `autoload.files` で、`HttpServer` を phalcon-core の `DefaultRunner` に登録している。設定を変えるときは `$app->useServer(new HttpServer(...))` で渡す。

待ち受け先とワーカ数はサーバ層 (`Transport`) の設定で、`HttpServer` は持たない。`HttpServer` を引数なしで作ると `new PhalconServerTransport()` (0.0.0.0:8080、ワーカ 1) を使う。

`PhalconServerTransport` の `workers` を 2 以上にすると、その数だけワーカを fork して並行に処理する。
並行処理のモデルと制約は [urassh/phalcon-server](../server#並行処理のモデル) を参照。

```php
(new HttpServer(new PhalconServerTransport(host: '0.0.0.0', port: 8080, workers: 4)))->run($handler);
```

### サーバ層を差し替える

`Transport` を実装して `HttpServer` に渡すと、phalcon-server の代わりに別のイベントループなどの上で動かせる。
実装は接続ごとに受け取ったバイト列をため、`HttpProtocol::isComplete()` が true を返したら `HttpProtocol::respond()` の戻り値を書き込んで接続を閉じる。

```php
use Urassh\Phalcon\Http\HttpProtocol;
use Urassh\Phalcon\Http\Transport;

final class MyTransport implements Transport
{
  public function __construct(
    private readonly string $address,
  ) {
  }

  public function serve(HttpProtocol $protocol): never
  {
    // $this->address で待ち受けて、揃ったリクエストを $protocol->respond() に渡す
  }
}

(new HttpServer(new MyTransport('0.0.0.0:8080')))->run($handler);
```

phalcon-server の `Server` を設定して使いたいときは `PhalconServerTransport` に渡す。

```php
use Urassh\Phalcon\Http\PhalconServerTransport;
use Urassh\Phalcon\Server\Server;

new HttpServer(new PhalconServerTransport(host: '0.0.0.0', port: 8080, server: new Server($platform)));
```

## 公開 API

| 型 | 役割 |
|---|---|
| `HttpServer` | `new HttpServer(?Transport $transport = null)` で作り、`run(RequestHandlerInterface $handler): never` で起動する。phalcon-core の `Runner` を実装するので、phalcon-framework の `Phalcon::useServer()` に渡せる |
| `Transport` | サーバ層のインターフェース。`serve(HttpProtocol $protocol): never` |
| `PhalconServerTransport` | phalcon-server の `Server` を使う既定の `Transport`。`new PhalconServerTransport(string $host = '0.0.0.0', int $port = 8080, int $workers = 1, ?Server $server = null)` |
| `HttpProtocol` | リクエストのバイト列から応答のバイト列を作る。`isComplete(string): bool` と `respond(string): string` |

`RequestParser`, `ResponseSerializer`, `Headers` は `@internal`。

## 挙動

- ハンドラに渡すリクエストは `Urassh\Phalcon\Core\ServerRequest`。リクエストターゲットはそのまま `getRequestTarget()` で読め、クエリ文字列は `getQueryParams()` に入る
- `Content-Length` と `Connection: close` はサーバが付け、ハンドラの指定は無視する
- 1 つのヘッダに複数の値があるときは `, ` で結合して 1 行にする。`Set-Cookie` だけは値ごとに行を分ける
- リクエストをパースできないときは `400 Bad Request`、ハンドラが例外を投げたときは `500 Internal Server Error` を返す (例外はログに出し、レスポンスには含めない)

## 制限

- ハンドラは 1 プロセス内で直列に呼ぶ。ハンドラがブロックしている間、そのプロセスはほかの接続を処理しない。並行に処理したいときは `workers` を増やす (ワーカ間でメモリ上の状態は共有されない)
- 1 リクエストごとに接続を閉じる (keep-alive なし)
- リクエストは 1 MiB まで
- HTTPS、chunked transfer encoding、multipart/form-data のパースには対応しない (`getParsedBody()` は常に `null`)
