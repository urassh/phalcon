<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Internal\Config\Config;
use Urassh\Phalcon\Internal\Middleware\Pipeline;
use Urassh\Phalcon\Internal\Routing\Router;
use Urassh\Phalcon\Internal\Routing\RouterHandler;
use Urassh\Phalcon\Internal\View\PhpRenderer;

/**
 * ルートを登録して PSR-15 のハンドラとして動く。起動は urassh/phalcon-http の HttpServer などに任せる
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

  public function __construct()
  {
    $this->router = new Router();
    $this->config = Config::empty();
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
   */
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    $handler = new Pipeline($this->middleware, new RouterHandler($this->router, new Psr17Factory()));

    return $handler->handle($request);
  }
}
