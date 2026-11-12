<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
final class App implements RequestHandlerInterface
{
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
   * YAML の設定ファイルを読み込んで App を作る
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
