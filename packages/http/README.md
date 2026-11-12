# urassh/phalcon-http

任意の PSR-15 ハンドラ (`Psr\Http\Server\RequestHandlerInterface`) を動かす HTTP/1.1 サーバ。
ループとソケットは [urassh/phalcon-server](../server)、PSR-7 の実装は [urassh/phalcon-core](../core) を使う。

> このリポジトリは [urassh/phalcon](https://github.com/urassh/phalcon) モノレポから split した読み取り専用のコピー。Issue と PR はモノレポで受け付ける。

## インストール

```sh
composer require urassh/phalcon-http
```

## 使い方

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Http\HttpServer;

$handler = new class () implements RequestHandlerInterface {
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return new Response(200, ['Content-Type' => 'text/plain'], "Hello, {$request->getUri()->getPath()}\n");
  }
};

(new HttpServer())->serve($handler, host: '0.0.0.0', port: 8080);
```

[urassh/phalcon-framework](../framework) の `App` もそのまま渡せる。

## 公開 API

| 型 | 役割 |
|---|---|
| `HttpServer` | `serve(RequestHandlerInterface $handler, string $host = '0.0.0.0', int $port = 8080): never` |
| `HttpProtocol` | phalcon-server の `Protocol` 実装。`Server` と組み合わせて使うとき用 |

`RequestParser`, `ResponseSerializer`, `Headers` は `@internal`。

## 挙動

- ハンドラに渡すリクエストは `Urassh\Phalcon\Core\ServerRequest`。リクエストターゲットはそのまま `getRequestTarget()` で読め、クエリ文字列は `getQueryParams()` に入る
- `Content-Length` と `Connection: close` はサーバが付け、ハンドラの指定は無視する
- 1 つのヘッダに複数の値があるときは `, ` で結合して 1 行にする。`Set-Cookie` だけは値ごとに行を分ける
- リクエストをパースできないときは `400 Bad Request`、ハンドラが例外を投げたときは `500 Internal Server Error` を返す (例外はログに出し、レスポンスには含めない)

## 制限

- 接続は 1 つずつ順番に処理する (並行処理なし)
- 1 リクエストごとに接続を閉じる (keep-alive なし)
- リクエストは 1 MiB まで
- HTTPS、chunked transfer encoding、multipart/form-data のパースには対応しない (`getParsedBody()` は常に `null`)
