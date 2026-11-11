# urassh/phalcon-core

PSR-7 (`psr/http-message` 2.0) と PSR-17 (`psr/http-factory`) の最小限の実装。
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

```php
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Core\ServerRequest;

$request = new ServerRequest('GET', '/users/42?sort=asc', ['Accept' => 'application/json']);
$response = new Response(201, ['Location' => '/users/42'], '{"id":42}');
```

## 実装の範囲

- `with*()` は常に新しいインスタンスを返す
- ヘッダ名は大文字小文字を区別せずに比較し、最初に設定したときの綴りを保つ。不正な名前や値 (改行を含むなど) は `InvalidArgumentException`
- `Stream` は文字列を保持するインメモリ実装。`createStreamFromFile()` / `createStreamFromResource()` は中身を読み込んだコピーを返し、書き込んでも元のファイルには反映されない
- `Psr17Factory::createServerRequest()` は URI のクエリ文字列から `getQueryParams()` を埋める
- `UploadedFile` は型を満たすための最小実装。multipart のパースはしない
- `RequestFactoryInterface` と `UploadedFileFactoryInterface` は実装していない
