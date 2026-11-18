# urassh/phalcon-core

PSR-7 (`psr/http-message` 2.0) と PSR-17 (`psr/http-factory`) の最小限の実装と、PSR-15 のハンドラを動かす `Runner` のインターフェース。
[urassh/phalcon-http](../http) と [urassh/phalcon-framework](../framework) が共通で使う。

> このリポジトリは [urassh/phalcon](https://github.com/urassh/phalcon) モノレポから split した読み取り専用のコピー。Issue と PR はモノレポで受け付ける。

## インストール

```sh
composer require urassh/phalcon-core
```

## 公開 API

| 型 | 実装するインターフェース |
|---|---|
| `Request` | `RequestInterface` |
| `ServerRequest` | `ServerRequestInterface` |
| `Response` | `ResponseInterface` |
| `Stream` | `StreamInterface` |
| `Uri` | `UriInterface` |
| `UploadedFile` | `UploadedFileInterface` |
| `Psr17Factory` | `ResponseFactoryInterface`, `ServerRequestFactoryInterface`, `StreamFactoryInterface`, `UriFactoryInterface` |
| `SapiEmitter` | なし。`emit(ResponseInterface): void` で応答を `header()` と `echo` で書き出す |
| `Runner` | なし (自前のインターフェース)。`run(RequestHandlerInterface): void` で PSR-15 のハンドラを動かすもの。urassh/phalcon-http の `HttpServer` も実装する |
| `SapiRunner` | `Runner`。`fromGlobals()` → ハンドラ → `SapiEmitter` で 1 リクエストを処理する |
| `DefaultRunner` | なし。CLI から起動するときに使う `Runner` を、インストールされたパッケージが `register(Closure(array): Runner)` で登録し、`create(array{host?: string, port?: int, workers?: int} $options = []): ?Runner` で作る。urassh/phalcon-http が `HttpServer` を登録し、phalcon-framework は設定ファイルの `server` を `$options` に渡す |

```php
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Core\ServerRequest;

$request = new ServerRequest('GET', '/users/42?sort=asc', ['Accept' => 'application/json']);
$response = new Response(201, ['Location' => '/users/42'], '{"id":42}');
```

### PHP-FPM などの SAPI で使う

`SapiRunner` に任意の PSR-15 ハンドラを渡す。スーパーグローバルから `ServerRequest::fromGlobals()` でリクエストを作り、応答を `SapiEmitter` で書き出す。

```php
use Urassh\Phalcon\Core\SapiRunner;

(new SapiRunner())->run($handler);
```

途中に処理を挟みたいときは、`fromGlobals()` と `SapiEmitter` を直接使う。

```php
use Urassh\Phalcon\Core\SapiEmitter;
use Urassh\Phalcon\Core\ServerRequest;

(new SapiEmitter())->emit($handler->handle(ServerRequest::fromGlobals()));
```

## 実装の範囲

- `with*()` は常に新しいインスタンスを返す
- ヘッダ名は大文字小文字を区別せずに比較し、最初に設定したときの綴りを保つ。不正な名前や値 (改行を含むなど) は `InvalidArgumentException`
- `Stream` は文字列を保持するインメモリ実装。`createStreamFromFile()` / `createStreamFromResource()` は中身を読み込んだコピーを返し、書き込んでも元のファイルには反映されない
- `Psr17Factory::createServerRequest()` は URI のクエリ文字列から `getQueryParams()` を埋める
- `UploadedFile` は型を満たすための最小実装。multipart のパースはしない
- `ServerRequest::fromGlobals()` は `$_SERVER` / `$_GET` / `$_COOKIE` / php://input から作る。引数で配列を渡すとスーパーグローバルの代わりに使う
  - URI は `Host` ヘッダ (なければ `SERVER_NAME` と `SERVER_PORT`)、`HTTPS`、`REQUEST_URI` から組み立てる。`X-Forwarded-*` は見ない
  - ヘッダは `HTTP_*` と `CONTENT_TYPE` / `CONTENT_LENGTH` から戻す
  - `getParsedBody()` は、PHP が `$_POST` を埋める POST のフォーム (`application/x-www-form-urlencoded` / `multipart/form-data`) のときだけ `$_POST`、それ以外は `null`
  - `$_FILES` は読まない
- `SapiEmitter::emit()` は、すでに出力が始まっていると `RuntimeException`。ヘッダごとに 1 つ目の値で PHP の既定のヘッダ (`Content-Type` など) を置き換える
- `RequestFactoryInterface` と `UploadedFileFactoryInterface` は実装していない
