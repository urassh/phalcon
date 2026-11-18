<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Core\Runner;
use Urassh\Phalcon\Core\SapiRunner;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Exception\HttpException;
use Urassh\Phalcon\Internal\Config\Config;
use Urassh\Phalcon\Internal\Middleware\CrossOriginProtection;
use Urassh\Phalcon\Internal\Middleware\FormBody;
use Urassh\Phalcon\Internal\Middleware\MethodOverride;
use Urassh\Phalcon\Internal\Middleware\Pipeline;
use Urassh\Phalcon\Internal\Routing\Router;
use Urassh\Phalcon\Internal\Routing\RouterHandler;
use Urassh\Phalcon\Internal\View\PhpRenderer;

/**
 * ルートを登録して PSR-15 のハンドラとして動く
 *
 * run() で起動する。CLI からは HttpServer などで常駐し、PHP-FPM などの SAPI からは 1 リクエストを処理する
 */
final class Phalcon implements RequestHandlerInterface
{
  /**
   * resources() が登録するアクション、メソッド、パスの接尾辞。`/new` が `/{id}` より先にマッチするよう、この順で登録する
   *
   * @var list<array{string, list<string>, string}>
   */
  private const RESOURCE_ROUTES = [
    ['index', ['GET'], ''],
    ['new', ['GET'], '/new'],
    ['create', ['POST'], ''],
    ['show', ['GET'], '/{id}'],
    ['edit', ['GET'], '/{id}/edit'],
    ['update', ['PATCH', 'PUT'], '/{id}'],
    ['destroy', ['DELETE'], '/{id}'],
  ];

  private readonly Router $router;

  private Config $config;

  /** @var list<MiddlewareInterface> */
  private array $middleware = [];

  /** 外せないよう add() のミドルウェアとは分けて持ち、常に最も外側で動かす */
  private CrossOriginProtection $crossOrigin;

  private ?Runner $server = null;

  public function __construct()
  {
    $this->router = new Router();
    $this->config = Config::empty();
    $this->crossOrigin = new CrossOriginProtection();
  }

  /**
   * YAML の設定ファイルを読み込んで Phalcon を作る
   *
   * @throws ConfigException ファイルが存在しない、YAML として不正、値の型が不正な場合
   */
  public static function fromConfig(string $path): self
  {
    $app = new self();
    $app->config = Config::load($path);
    $app->crossOrigin = new CrossOriginProtection($app->config->trustedOrigins());

    $directory = $app->config->viewDirectory();
    if ($directory !== null) {
      $app->useRenderer(new PhpRenderer($directory));
    }

    return $app;
  }

  /**
   * 設定値を `database.dsn` のようにドット区切りで参照する。キーがなければ null
   */
  public function config(string $key): mixed
  {
    return $this->config->get($key);
  }

  /**
   * Response::view() で使うテンプレートエンジンを差し替える
   */
  public function useRenderer(Renderer $renderer): self
  {
    $this->router->useRenderer($renderer);

    return $this;
  }

  /**
   * run() で使うサーバを登録する。urassh/phalcon-http の HttpServer など、phalcon-core の Runner を実装したものを渡す
   */
  public function useServer(Runner $server): self
  {
    $this->server = $server;

    return $this;
  }

  /**
   * すべてのテンプレート (エラーページを含む) に変数 `$name` として渡す値を登録する。同じ名前でもう一度呼ぶと置き換える
   *
   * Response::view() に渡したデータと名前がかぶったら、そちらを優先する
   *
   * @throws InvalidArgumentException 変数名として使えない名前の場合
   */
  public function share(string $name, mixed $value): self
  {
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1 || $name === 'this') {
      throw new InvalidArgumentException("Shared name must be a valid variable name, got '{$name}'");
    }

    $this->router->share($name, $value);

    return $this;
  }

  /**
   * エラーレスポンスの形式を決めるフックを登録する。もう一度呼ぶと置き換える
   *
   * ハンドラが投げた HttpException、ルートにマッチしない 404 / 405、想定外の例外 (500 の HttpException に包み、
   * 元の例外は getPrevious() で取れる) のたびに呼ばれる。null を返すと既定の形式 (JSON / エラーページ / テキスト) に任せる
   *
   * @param callable(HttpException, Request): ?Response $handler
   */
  public function onError(callable $handler): self
  {
    $this->router->onError(Closure::fromCallable($handler));

    return $this;
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function get(string $path, callable $handler): self
  {
    return $this->route('GET', $path, $handler);
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function post(string $path, callable $handler): self
  {
    return $this->route('POST', $path, $handler);
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function put(string $path, callable $handler): self
  {
    return $this->route('PUT', $path, $handler);
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function patch(string $path, callable $handler): self
  {
    return $this->route('PATCH', $path, $handler);
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function delete(string $path, callable $handler): self
  {
    return $this->route('DELETE', $path, $handler);
  }

  /**
   * @param callable(Request): Response $handler
   */
  public function route(string $method, string $path, callable $handler): self
  {
    $this->router->add($method, $path, Closure::fromCallable($handler));

    return $this;
  }

  /**
   * コントローラのアクションをリソースの規約でまとめて登録する
   *
   * `$app->resources('/users', new UsersController())` で `GET /users` が index、
   * `GET /users/{id}` が show、のように割り当てる。対応表は Controller を参照
   *
   * `$only` を渡すとそのアクションだけを、`$except` を渡すとそれ以外を登録する
   *
   * @param list<string>|null $only 登録するアクション。null ならコントローラが定義しているものすべて
   * @param list<string> $except 登録しないアクション
   *
   * @throws InvalidArgumentException 知らないアクション名を渡した場合、`$only` のアクションをコントローラが定義していない場合
   */
  public function resources(string $path, Controller $controller, ?array $only = null, array $except = []): self
  {
    $actions = array_column(self::RESOURCE_ROUTES, 0);
    foreach ([...$only ?? [], ...$except] as $action) {
      if (!in_array($action, $actions, true)) {
        throw new InvalidArgumentException(
          "Unknown action '{$action}'. Available actions: " . implode(', ', $actions),
        );
      }
    }

    $base = rtrim($path, '/');

    foreach (self::RESOURCE_ROUTES as [$action, $methods, $suffix]) {
      if (($only !== null && !in_array($action, $only, true)) || in_array($action, $except, true)) {
        continue;
      }

      $handler = self::action($controller, $action);
      if ($handler === null) {
        // only で明示したのに定義がなければ書き間違いとみなす
        if ($only !== null) {
          throw new InvalidArgumentException(
            get_debug_type($controller) . " does not define public action '{$action}'",
          );
        }

        continue;
      }

      $route = $base . $suffix;
      foreach ($methods as $method) {
        $this->router->add($method, $route === '' ? '/' : $route, $handler);
      }
    }

    return $this;
  }

  /**
   * public なインスタンスメソッドとして定義されていればアクションのクロージャを返す。なければ null
   */
  private static function action(Controller $controller, string $action): ?Closure
  {
    if (!method_exists($controller, $action)) {
      return null;
    }

    $method = new ReflectionMethod($controller, $action);
    if (!$method->isPublic() || $method->isStatic()) {
      return null;
    }

    return $method->getClosure($controller);
  }

  /**
   * ミドルウェアを追加する。先に追加したものほど外側で動く
   */
  public function add(MiddlewareInterface $middleware): self
  {
    $this->middleware[] = $middleware;

    return $this;
  }

  /**
   * リクエストを 1 件処理する。ミドルウェアを通してからルーティングする
   *
   * 別オリジンから送られた POST / PUT / PATCH / DELETE などは、ミドルウェアより先に 403 にする (CSRF 対策)。
   * そのあと urlencoded のフォームをパースし、POST のフォームに `_method` があればそのメソッドに置き換える。
   * ミドルウェアが投げた HttpException も、ハンドラが投げたときと同じ形式のエラーレスポンスにする
   */
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    $router = new RouterHandler($this->router, new Psr17Factory());
    // CSRF の判定は上書き前の POST に対して行うので、MethodOverride より外側に置く
    $handler = new Pipeline([$this->crossOrigin, new FormBody(), new MethodOverride(), ...$this->middleware], $router);

    try {
      return $handler->handle($request);
    } catch (HttpException $e) {
      return $router->error($e, $request);
    }
  }

  /**
   * アプリを動かす
   *
   * 次の順にサーバを選ぶ。HttpServer で常駐した場合は戻らない
   *
   * 1. useServer() で登録したサーバ
   * 2. PHP-FPM や php -S などの SAPI から呼ばれたら、スーパーグローバルのリクエストを 1 件処理する (SapiRunner)
   * 3. CLI から呼ばれたら、インストールされたパッケージが DefaultRunner に登録したサーバ (urassh/phalcon-http の HttpServer)
   *
   * @throws LogicException CLI から呼ばれ、サーバの登録も urassh/phalcon-http もない
   */
  public function run(): void
  {
    ($this->server ?? self::defaultServer())->run($this);
  }

  private static function defaultServer(): Runner
  {
    if (php_sapi_name() !== 'cli') {
      return new SapiRunner();
    }

    // CLI ではスーパーグローバルにリクエストがなく、応答も標準出力に書かれるだけなので、SapiRunner では黙って動かさない
    return DefaultRunner::create() ?? throw new LogicException(
      'No server is available: install urassh/phalcon-http, call useServer(), or run under PHP-FPM or php -S',
    );
  }
}
