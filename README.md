# urassh/phalcon

PHP の標準関数 (`stream_socket_server`) の上に組み立てた小さな HTTP サーバライブラリ。
ルートとハンドラを登録して `serve()` を呼ぶだけで Web サーバを立てられる。

## インストール

```sh
composer require urassh/phalcon
```

Packagist に公開するまでは、`composer.json` に GitHub リポジトリを追加して使う。

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/urassh/phalcon" }
    ],
    "require": {
        "urassh/phalcon": "^0.1"
    }
}
```

## 使い方

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\App;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

$app = new App();

$app->get('/', fn (Request $req) => Response::text("Hello, world!\n"));

$app->get('/users/{id}', fn (Request $req) => Response::json([
  'id' => $req->param('id'),
]));

$app->post('/echo', fn (Request $req) => Response::text($req->body()));

$app->serve(host: '0.0.0.0', port: 8080);
```

```sh
$ curl http://localhost:8080/users/42
{"id":"42"}
```

## 公開 API

利用者が触るのは次の型だけ。

| 型 | 役割 |
|---|---|
| `Urassh\Phalcon\App` | ルートの登録、設定の読み込み、サーバの起動 |
| `Urassh\Phalcon\Request` | ハンドラが受け取るリクエスト |
| `Urassh\Phalcon\Response` | ハンドラが返すレスポンス |
| `Urassh\Phalcon\Renderer` | テンプレートエンジンを差し替えるときに実装するインターフェース |
| `Urassh\Phalcon\Exception\ConfigException` | 設定ファイルの読み込みに失敗したときの例外 |
| `e()` | テンプレート用の HTML エスケープ関数 |

それ以外のクラス (`Urassh\Phalcon\Internal\` 以下) はすべて `@internal` で、マイナーバージョンでも予告なく変わる。
内部で使っている symfony/yaml の型や例外が公開 API に出てくることはない。

## 設定ファイル

ホスト名やポート、テンプレートのディレクトリは YAML ファイルにまとめて書ける。

```yaml
# phalcon.yaml
server:
  host: 0.0.0.0
  port: 8080

view:
  directory: views

# server / view 以外のキーは利用者が自由に使える
database:
  dsn: mysql:host=db;dbname=app
  user: app
  password: ${DB_PASSWORD}
```

```php
$app = App::fromConfig(__DIR__ . '/phalcon.yaml');

$pdo = new PDO(
  $app->config('database.dsn'),
  $app->config('database.user'),
  $app->config('database.password'),
);

$app->get('/', fn (Request $req) => Response::view('index', ['title' => 'Top']));

$app->serve();
```

- `view.directory` などの相対パスは、YAML ファイルがあるディレクトリを基準に解決する
- 値に `${NAME}` と書くと環境変数 `NAME` の値に置き換える。未定義の環境変数を参照するとエラーになる。パスワードなどはこの方法で YAML の外から渡す
- 値の優先順位は `serve()` の引数 > YAML > デフォルト値 (`host: 0.0.0.0`, `port: 8080`)
- ファイルが存在しない、YAML として不正、`server.port` が整数でない、といった場合は `App::fromConfig()` が `ConfigException` を投げる

| キー | 型 | デフォルト | 説明 |
|---|---|---|---|
| `server.host` | `string` | `0.0.0.0` | 待ち受けるアドレス |
| `server.port` | `int` | `8080` | 待ち受けるポート |
| `view.directory` | `string` | なし | テンプレートを置くディレクトリ。`Response::view()` を使うなら必須 |

## API

### `App`

| メソッド | 説明 |
|---|---|
| `new App()` | 設定ファイルを使わずに `App` を作る |
| `App::fromConfig(string $path): self` | YAML の設定ファイルを読み込んで `App` を作る |
| `get(string $path, callable $handler): self` | GET のルートを登録する |
| `post(string $path, callable $handler): self` | POST のルートを登録する |
| `put(string $path, callable $handler): self` | PUT のルートを登録する |
| `patch(string $path, callable $handler): self` | PATCH のルートを登録する |
| `delete(string $path, callable $handler): self` | DELETE のルートを登録する |
| `route(string $method, string $path, callable $handler): self` | 任意のメソッドでルートを登録する |
| `config(string $key): mixed` | 設定値を `'database.dsn'` のようにドット区切りで参照する。キーがなければ `null` |
| `useRenderer(Renderer $renderer): self` | `Response::view()` で使うテンプレートエンジンを差し替える |
| `serve(?string $host = null, ?int $port = null): void` | サーバを起動する。プロセスが終了するまで戻らない。引数を省略すると設定ファイルの値 (なければデフォルト値) を使う |

ルート登録メソッドは `$this` を返すので、チェーンして書ける。

```php
$app
  ->get('/', $index)
  ->post('/users', $createUser);
```

#### ハンドラ

ハンドラは `Request` を受け取って `Response` を返す callable。

```php
fn (Request $req): Response => Response::text('OK');
```

クロージャのほか、`__invoke` を持つクラスのインスタンスや `[$controller, 'method']` も渡せる。

#### パスパターン

- `/users` … 完全一致
- `/users/{id}` … `{name}` は `/` を含まない 1 セグメントにマッチし、`$req->param('name')` で取得できる
- マッチ対象はパス部分だけで、クエリ文字列 (`?` 以降) は含まない
- 複数のルートにマッチする場合は、先に登録したほうが優先される

### `Request`

| メソッド | 戻り値 | 説明 |
|---|---|---|
| `method()` | `string` | `GET`, `POST` など |
| `path()` | `string` | パス部分 (`/users/42`) |
| `param(string $name)` | `?string` | ルートパラメータ。なければ `null` |
| `query(string $name)` | `?string` | クエリパラメータ。なければ `null` |
| `header(string $name)` | `?string` | ヘッダの値。名前の大文字小文字は区別しない。同名が複数あれば最初の値。なければ `null` |
| `body()` | `string` | ボディ |
| `json()` | `mixed` | ボディを JSON としてデコードした結果 (オブジェクトは連想配列になる) |

`json()` でボディが不正な JSON だった場合は、ハンドラの処理を打ち切って `400 Bad Request` を返す。

### `Response`

`Response` はイミュータブルで、`with*` メソッドは新しいインスタンスを返す。

| メソッド | 説明 |
|---|---|
| `Response::text(string $body, int $status = 200)` | `Content-Type: text/plain; charset=utf-8` |
| `Response::html(string $body, int $status = 200)` | `Content-Type: text/html; charset=utf-8` |
| `Response::json(mixed $data, int $status = 200)` | JSON にエンコードした結果をボディにし、`Content-Type: application/json` を付ける |
| `Response::view(string $name, array $data = [], int $status = 200)` | テンプレート `$name` を描画した結果を HTML として返す |
| `withStatus(int $status)` | ステータスコードを差し替える |
| `withHeader(string $name, string $value)` | ヘッダを追加 (同名があれば置き換え) する |

```php
Response::json(['error' => 'not found'], 404);

Response::text('created', 201)
  ->withHeader('Location', '/users/42');
```

`Content-Length` と `Connection: close` はサーバが自動で付ける。

`Response::json()` には配列のほか、`JsonSerializable` を実装したオブジェクトも渡せる。

```php
final class User implements JsonSerializable
{
  public function __construct(
    private readonly int $id,
    private readonly string $name,
  ) {
  }

  public function jsonSerialize(): array
  {
    return ['id' => $this->id, 'name' => $this->name];
  }
}

Response::json(new User(42, 'urassh'));
```

### テンプレート

`Response::view()` は、設定ファイルの `view.directory` にある `{$name}.php` を描画する。
`$data` のキーはテンプレート内で変数になる。

```php
$app->get('/users/{id}', fn (Request $req) => Response::view('users/show', [
  'name' => $req->param('id'),
]));
```

```php
<!-- views/users/show.php -->
<h1>Hello, <?= e($name) ?></h1>
```

- テンプレートに値を出力するときは `e()` (`htmlspecialchars` のラッパー) でエスケープする
- `view.directory` が未設定、またはテンプレートが存在しない場合は `500 Internal Server Error` になる

Twig などのテンプレートエンジンを使いたい場合は、`Renderer` を実装して `useRenderer()` で渡す。

```php
interface Renderer
{
  /**
   * @param array<string, mixed> $data
   */
  public function render(string $name, array $data): string;
}
```

### エラー時の挙動

| 状況 | レスポンス |
|---|---|
| リクエストをパースできない | `400 Bad Request` |
| `$req->json()` でボディが不正な JSON | `400 Bad Request` |
| パスにマッチするルートがない | `404 Not Found` |
| パスはマッチするがメソッドが違う | `405 Method Not Allowed` (`Allow` ヘッダ付き) |
| ハンドラが例外を投げた | `500 Internal Server Error` (例外はサーバのログに出力し、レスポンスには含めない) |

## DB と組み合わせる

このライブラリは DB へのアクセス手段を提供しない。PDO などを利用者が用意し、ハンドラに渡す。

依存を持つハンドラは、`__invoke` を持つクラスにしてコンストラクタで受け取るとテストしやすい。

```php
final class ShowUser
{
  public function __construct(
    private readonly PDO $pdo,
  ) {
  }

  public function __invoke(Request $req): Response
  {
    $stmt = $this->pdo->prepare('SELECT id, name FROM users WHERE id = :id');
    $stmt->execute(['id' => $req->param('id')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user === false
      ? Response::json(['error' => 'not found'], 404)
      : Response::json($user);
  }
}

$app = App::fromConfig(__DIR__ . '/phalcon.yaml');

$pdo = new PDO(
  $app->config('database.dsn'),
  $app->config('database.user'),
  $app->config('database.password'),
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$app->get('/users/{id}', new ShowUser($pdo));

$app->post('/users', function (Request $req) use ($pdo) {
  $body = $req->json();
  $stmt = $pdo->prepare('INSERT INTO users (name) VALUES (:name)');
  $stmt->execute(['name' => $body['name']]);
  $id = $pdo->lastInsertId();

  return Response::json(['id' => (int) $id], 201)
    ->withHeader('Location', "/users/{$id}");
});

$app->serve();
```

### 常駐プロセスでの注意

PHP-FPM と違い、`serve()` を呼んだプロセスは終了せずにリクエストを処理し続ける。

- PDO の接続は起動時に 1 度だけ作り、全リクエストで使い回す。DB 側がアイドル状態の接続を切ることがあるので、長時間動かす場合は再接続を考慮する
- static 変数やグローバル変数に入れた値は次のリクエストにも残る。リクエストごとの状態はハンドラ内のローカル変数に閉じる
- 接続を 1 つずつ処理するため、遅いクエリがあると後続のリクエストがすべて待たされる

## 制限

- 接続は 1 つずつ順番に処理する (並行処理なし)
- 1 リクエストごとに接続を閉じる (keep-alive なし)
- リクエストは 1 MiB まで
- HTTPS、chunked transfer encoding には対応しない

## 開発環境

Docker 上で PHP 8.3 + Composer を使って開発する。

```sh
make build     # イメージをビルド
make install   # composer install
make test      # PHPUnit
make analyse   # PHPStan
make shell     # コンテナに入る
```

## ディレクトリ構成

```
src/            ライブラリ本体 (名前空間 Urassh\Phalcon\)
  App.php         公開 API
  Request.php
  Response.php
  Renderer.php
  Exception/
  functions.php   e() など
  Internal/       内部実装 (@internal)。Server, Http, Routing, Config, View など
tests/          テスト (名前空間 Urassh\Phalcon\Tests\)
docker/php/     開発用 PHP イメージ
```
