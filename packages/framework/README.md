# urassh/phalcon-framework

ルートとハンドラを登録して使う小さな Web フレームワーク。
`Phalcon` は PSR-15 の `RequestHandlerInterface` を実装しているので、PSR-15 のハンドラを動かせるサーバならどれでも起動できる。
ループやソケットには依存しない。

> このリポジトリは [urassh/phalcon](https://github.com/urassh/phalcon) モノレポから split した読み取り専用のコピー。Issue と PR はモノレポで受け付ける。

## インストール

```sh
composer require urassh/phalcon-framework urassh/phalcon-http
```

`urassh/phalcon-http` は起動に使う HTTP サーバ。別の PSR-15 対応サーバで動かすなら不要。

## 使い方

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Http\HttpServer;
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

```sh
$ curl http://localhost:8080/users/42
{"id":"42"}
```

## 公開 API

利用者が触るのは次の型だけ。

| 型 | 役割 |
|---|---|
| `Urassh\Phalcon\Phalcon` | ルートとミドルウェアの登録、設定の読み込み。PSR-15 の `RequestHandlerInterface` |
| `Urassh\Phalcon\Request` | ハンドラが受け取るリクエスト |
| `Urassh\Phalcon\Response` | ハンドラが返すレスポンス |
| `Urassh\Phalcon\Controller` | `resources()` に渡すコントローラの基底クラス |
| `Urassh\Phalcon\Renderer` | テンプレートエンジンを差し替えるときに実装するインターフェース |
| `Urassh\Phalcon\Exception\ConfigException` | 設定ファイルの読み込みに失敗したときの例外 |
| `Urassh\Phalcon\Exception\HttpException` とそのサブクラス | ハンドラから投げてエラーレスポンスを返す例外 |
| `e()` | テンプレート用の HTML エスケープ関数 |

それ以外のクラス (`Urassh\Phalcon\Internal\` 以下) はすべて `@internal` で、マイナーバージョンでも予告なく変わる。
リクエストとレスポンスの PSR-7 実装には [urassh/phalcon-core](../core) を使う。
内部で使っている symfony/yaml の型や例外が公開 API に出てくることはない。

## 設定ファイル

ホスト名やポート、テンプレートのディレクトリは YAML ファイルにまとめて書ける。

```yaml
# phalcon.yaml
server:
  host: 0.0.0.0
  port: 8080
  workers: 4

view:
  directory: views

# server / view 以外のキーは利用者が自由に使える
database:
  dsn: mysql:host=db;dbname=app
  user: app
  password: ${DB_PASSWORD}
```

```php
$app = Phalcon::fromConfig(__DIR__ . '/phalcon.yaml');

$pdo = new PDO(
  $app->config('database.dsn'),
  $app->config('database.user'),
  $app->config('database.password'),
);

$app->get('/', fn (Request $req) => Response::view('index', ['title' => 'Top']));

(new HttpServer(
  host: $app->config('server.host') ?? '0.0.0.0',
  port: $app->config('server.port') ?? 8080,
  workers: $app->config('server.workers') ?? 1,
))->serve($app);
```

- `view.directory` などの相対パスは、YAML ファイルがあるディレクトリを基準に解決する
- 値に `${NAME}` と書くと環境変数 `NAME` の値に置き換える。未定義の環境変数を参照するとエラーになる。パスワードなどはこの方法で YAML の外から渡す
- `server.host` / `server.port` / `server.workers` は `Phalcon` が使うわけではなく、`$app->config()` で取り出してサーバに渡す。`Phalcon::fromConfig()` は型と範囲だけを検証する
- ファイルが存在しない、YAML として不正、`server.port` や `server.workers` が整数でない、といった場合は `Phalcon::fromConfig()` が `ConfigException` を投げる

| キー | 型 | デフォルト | 説明 |
|---|---|---|---|
| `server.host` | `string` | なし | 待ち受けるアドレス (サーバに渡す用) |
| `server.port` | `int` | なし | 待ち受けるポート (サーバに渡す用) |
| `server.workers` | `int` (1 以上) | なし | ワーカプロセスの数 (サーバに渡す用) |
| `view.directory` | `string` | なし | テンプレートを置くディレクトリ。`Response::view()` を使うなら必須 |

## API

### `Phalcon`

| メソッド | 説明 |
|---|---|
| `new Phalcon()` | 設定ファイルを使わずに `Phalcon` を作る |
| `Phalcon::fromConfig(string $path): self` | YAML の設定ファイルを読み込んで `Phalcon` を作る |
| `get(string $path, callable $handler): self` | GET のルートを登録する |
| `post(string $path, callable $handler): self` | POST のルートを登録する |
| `put(string $path, callable $handler): self` | PUT のルートを登録する |
| `patch(string $path, callable $handler): self` | PATCH のルートを登録する |
| `delete(string $path, callable $handler): self` | DELETE のルートを登録する |
| `route(string $method, string $path, callable $handler): self` | 任意のメソッドでルートを登録する |
| `resources(string $path, Controller $controller, ?array $only = null, array $except = []): self` | コントローラのアクションをリソースの規約でまとめて登録する |
| `config(string $key): mixed` | 設定値を `'database.dsn'` のようにドット区切りで参照する。キーがなければ `null` |
| `useRenderer(Renderer $renderer): self` | `Response::view()` で使うテンプレートエンジンを差し替える |
| `onError(callable $handler): self` | エラーレスポンスの形式を決めるフックを登録する ([エラーの形式を変える](#エラーの形式を変える)) |
| `add(MiddlewareInterface $middleware): self` | PSR-15 のミドルウェアを追加する。先に追加したものほど外側で動く |
| `handle(ServerRequestInterface $request): ResponseInterface` | リクエストを 1 件処理する (PSR-15)。ミドルウェアを通してからルーティングする |

ルート登録メソッドは `$this` を返すので、チェーンして書ける。

```php
$app
  ->get('/', $index)
  ->post('/users', $createUser);
```

#### ミドルウェア

PSR-15 の `MiddlewareInterface` をそのまま使える。ミドルウェアが付けたリクエストの属性は `$req->param()` (文字列のとき) か `$req->psr()->getAttribute()` で読める。

```php
$app->add(new class () implements MiddlewareInterface {
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $response = $handler->handle($request);

    return $response->withHeader('X-Powered-By', 'phalcon');
  }
});
```

#### ハンドラ

ハンドラは `Request` を受け取って `Response` を返す callable。

```php
fn (Request $req): Response => Response::text('OK');
```

クロージャのほか、`__invoke` を持つクラスのインスタンスや `[$controller, 'method']` も渡せる。

#### コントローラ

`Controller` を継承したクラスを `resources()` に渡すと、アクション名に応じて次の表のとおりルートを登録する。

```php
use Urassh\Phalcon\Controller;

final class UsersController extends Controller
{
  public function index(Request $req): Response
  {
    return Response::json([['id' => 1]]);
  }

  public function show(Request $req): Response
  {
    return Response::json(['id' => $req->param('id')]);
  }
}

$app->resources('/users', new UsersController());
```

| アクション | メソッド | パス |
|---|---|---|
| `index` | GET | `/users` |
| `new` | GET | `/users/new` |
| `create` | POST | `/users` |
| `show` | GET | `/users/{id}` |
| `edit` | GET | `/users/{id}/edit` |
| `update` | PATCH / PUT | `/users/{id}` |
| `destroy` | DELETE | `/users/{id}` |

- アクションは `Request` を受け取って `Response` を返す public メソッド。定義していないアクションのルートは登録しない
- `/users/new` は `/users/{id}` より先に登録されるので、`new` を定義していれば `show` に `new` が渡ることはない

登録するアクションは名前付き引数 `only` / `except` で絞れる。

```php
$app->resources('/users', new UsersController(), only: ['index', 'show']);
$app->resources('/posts', new PostsController(), except: ['destroy']);
```

- `only` を渡すとそのアクションだけを、`except` を渡すとそれ以外を登録する。両方渡すと `only` から `except` を除いたものになる
- 表にないアクション名を渡すと `InvalidArgumentException` を投げる
- `only` に書いたアクションをコントローラが定義していない場合も `InvalidArgumentException` を投げる。`except` やどちらも渡さない場合は、定義していないアクションを黙って飛ばす

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
| `psr()` | `ServerRequestInterface` | 包んでいる PSR-7 のリクエスト |

ルートパラメータは PSR-7 のリクエストの属性 (`getAttribute('id')`) としても渡る。

`json()` でボディが不正な JSON だった場合は、ハンドラの処理を打ち切って `400 Bad Request` を返す。

### `Response`

`Response` はイミュータブルで、`with*` メソッドは新しいインスタンスを返す。

| メソッド | 説明 |
|---|---|
| `Response::text(string $body, int $status = 200)` | `Content-Type: text/plain; charset=utf-8` |
| `Response::html(string $body, int $status = 200)` | `Content-Type: text/html; charset=utf-8` |
| `Response::json(mixed $data, int $status = 200)` | JSON にエンコードした結果をボディにし、`Content-Type: application/json` を付ける |
| `Response::view(string $name, array $data = [], int $status = 200)` | テンプレート `$name` を描画した結果を HTML として返す |
| `Response::redirect(string $location, int $status = 302)` | `Location` ヘッダを付けてリダイレクトする。ボディは空 |
| `Response::seeOther(string $location)` | `303 See Other` でリダイレクトする。フォームの POST のあとに GET で戻すときに使う |
| `Response::back(Request $req, string $fallback = '/', int $status = 302)` | Referer のページへ戻る。Referer がない、またはほかのサイトを指していれば `$fallback` へ戻す |
| `Response::created(string $location, mixed $data = null)` | `201 Created` と `Location` ヘッダを返す。`$data` を渡すと JSON にしてボディにし、`null` ならボディと `Content-Type` を付けない |
| `Response::noContent()` | `204 No Content`。ボディと `Content-Type` を付けない |
| `withStatus(int $status)` | ステータスコードを差し替える |
| `withHeader(string $name, string $value)` | ヘッダを追加 (同名があれば置き換え) する |

```php
Response::json(['error' => 'not found'], 404);

Response::created('/users/42', ['id' => 42]);   // 201 + Location + JSON
```

フォームを POST で受けたあとに一覧や詳細へ戻すときは、`seeOther()` (303) を使うと GET でアクセスし直させられる。
入力をやり直させるときは `back()` で元のページへ戻す。

```php
$app->post('/memos', function (Request $req) use ($memos) {
  $id = $memos->create($req->json());
  if ($id === null) {
    return Response::back($req, '/memos/new', 303);   // 入力が不正なら元のフォームへ
  }

  return Response::seeOther("/memos/{$id}");
});
```

- `back()` が使うのは Referer のパスとクエリ文字列だけ。ホスト (`Host` ヘッダと比べる) が違う場合や、`//evil.com` のようにほかのホストと解釈されるパスの場合は `$fallback` に戻すので、オープンリダイレクトにはならない
- `withHeader()` はヘッダ名がトークンでない場合と、値に改行や NUL を含む場合に `InvalidArgumentException` を投げる。ユーザの入力を `redirect()` に渡してもヘッダインジェクションは起きない

`Content-Length` と `Connection: close` は urassh/phalcon-http が自動で付ける。

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

  /**
   * テンプレート $name があるか。エラーページの有無の判定に使う
   */
  public function exists(string $name): bool;
}
```

### エラー時の挙動

| 状況 | レスポンス |
|---|---|
| リクエストをパースできない | `400 Bad Request` (urassh/phalcon-http が返す) |
| `$req->json()` でボディが不正な JSON | `400 Bad Request` |
| パスにマッチするルートがない | `404 Not Found` |
| パスはマッチするがメソッドが違う | `405 Method Not Allowed` (`Allow` ヘッダ付き) |
| ハンドラが `HttpException` を投げた | 例外のステータス、メッセージ、ヘッダ |
| ハンドラがそれ以外の例外を投げた | `500 Internal Server Error` (例外はサーバのログに出力し、レスポンスには含めない) |

#### HTTP の例外

ハンドラやコントローラのアクションから `Urassh\Phalcon\Exception\` の例外を投げると、処理を打ち切ってそのステータスを返す。

| 例外 | ステータス |
|---|---|
| `BadRequestException` | `400 Bad Request` |
| `UnauthorizedException` | `401 Unauthorized` |
| `ForbiddenException` | `403 Forbidden` |
| `NotFoundException` | `404 Not Found` |
| `MethodNotAllowedException` | `405 Method Not Allowed` (`Allow` ヘッダ付き) |
| `ConflictException` | `409 Conflict` |
| `UnprocessableEntityException` | `422 Unprocessable Entity` |
| `HttpException` | 任意のステータス |

```php
final class UsersController extends Controller
{
  public function show(Request $req): Response
  {
    $user = $this->users->find($req->param('id'))
      ?? throw new NotFoundException('User not found');

    return Response::json($user);
  }
}

throw new UnauthorizedException(headers: ['WWW-Authenticate' => 'Bearer']);
throw new HttpException(503);
```

メッセージを省略するとステータスの理由句 (`Not Found` など) になる。メッセージはクライアントにそのまま見えるので、内部の情報は入れない。

#### エラーページ

view ディレクトリに `errors/{ステータス}.php` があれば、エラーレスポンスをそのテンプレートで描画する。
なければメッセージをテキスト (`text/plain`) で返す。ルートにマッチしない 404 / 405 や、想定外の例外による 500 も同じ扱いになる。

```php
<!-- views/errors/404.php -->
<h1><?= e((string) $status) ?></h1>
<p><?= e($message) ?></p>
```

- テンプレートには `$status` (int) と `$message` (string) が渡る。500 の `$message` は常に `Internal Server Error` で、元の例外のメッセージは渡さない
- エラーページの描画自体に失敗した場合は、ログに出力してテキストで返す

#### JSON のエラー

リクエストの `Accept` に JSON (`application/json` や `application/problem+json` など) があり、`text/html` がなければ、エラーを JSON で返す。

```sh
$ curl -H 'Accept: application/json' http://localhost:8080/api/memos/999
{"error":"Memo not found"}
```

- API のハンドラからも、HTML のときと同じく `NotFoundException` などを投げればよい
- ブラウザのページ遷移は `text/html` を送るので、エラーページ (またはテキスト) のまま
- `Accept` を送らないクライアント (`curl` の既定など) にはテキストを返す。パスで切り替えたい場合は次の `onError()` を使う

#### エラーの形式を変える

`onError()` でフックを登録すると、エラーレスポンスの形式を自由に決められる。null を返すと既定の形式 (JSON / エラーページ / テキスト) に任せる。

```php
$app->onError(function (HttpException $e, Request $req): ?Response {
  if (!str_starts_with($req->path(), '/api/')) {
    return null;
  }

  return Response::json(['code' => $e->status(), 'message' => $e->getMessage()], $e->status());
});
```

- ハンドラが投げた `HttpException`、ルートにマッチしない 404 / 405、想定外の例外による 500 のたびに呼ばれる
- 想定外の例外は `HttpException(500)` に包んで渡す。元の例外は `$e->getPrevious()` で取れるので、エラー監視サービスへの送信などに使える。レスポンスにはそのメッセージを含めないこと
- 例外のヘッダ (405 の `Allow` など) は、返したレスポンスに同名のヘッダがなければ付ける
- `Response::view()` を返してもよい。フック自体が例外を投げた場合は、ログに出力して既定の形式で返す
- もう一度 `onError()` を呼ぶと置き換える

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

$app = Phalcon::fromConfig(__DIR__ . '/phalcon.yaml');

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

  return Response::created("/users/{$id}", ['id' => (int) $id]);
});

(new HttpServer())->serve($app);
```

### 常駐プロセスでの注意

PHP-FPM と違い、`HttpServer::serve()` を呼んだプロセスは終了せずにリクエストを処理し続ける。

- PDO の接続は起動時に 1 度だけ作り、全リクエストで使い回す。DB 側がアイドル状態の接続を切ることがあるので、長時間動かす場合は再接続を考慮する
- static 変数やグローバル変数に入れた値は次のリクエストにも残る。リクエストごとの状態はハンドラ内のローカル変数に閉じる
- ハンドラは 1 プロセス内で直列に呼ばれるため、遅いクエリがあるとそのプロセスに来た後続のリクエストは待たされる。`workers` を増やすとワーカ数まで並行に処理できる
- `workers` が 2 以上のとき、PDO などは fork でワーカごとにコピーされ、ワーカ間でメモリ上の状態は共有されない。fork 前に開いた DB 接続を複数のワーカで使い回すと壊れるので、接続はワーカごとに作る (最初のリクエストで遅延して作るなど)

## 0.1 からの移行

- `App` を `Phalcon` に改名した。`use Urassh\Phalcon\App;` を `use Urassh\Phalcon\Phalcon;` に書き換える
- `HttpServer` の `host` / `port` / `workers` はコンストラクタで渡す。`(new HttpServer(host: '0.0.0.0', port: 8080))->serve($app)`
- `Renderer` に `exists(string $name): bool` が増えた。独自の `Renderer` を実装している場合は追加する
- パッケージ名を `urassh/phalcon` から `urassh/phalcon-framework` に変えた。名前空間 (`Urassh\Phalcon\`) は変わらない
- `App::serve()` を削除した。`urassh/phalcon-http` の `HttpServer` に `App` を渡して起動する
- `App::handle()` は PSR-7 の `ServerRequestInterface` を受け取り `ResponseInterface` を返す
- `Request::parse()` などの `@internal` メソッドを削除した。リクエストのパースは urassh/phalcon-http が行う
