<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use Closure;
use Urassh\Phalcon\Internal\Http\HttpProtocol;
use Urassh\Phalcon\Internal\Routing\Router;
use Urassh\Phalcon\Internal\Server\Server;

final class App
{
  private const DEFAULT_HOST = '0.0.0.0';
  private const DEFAULT_PORT = 8080;

  private readonly Router $router;

  public function __construct()
  {
    $this->router = new Router();
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
   * リクエストを 1 件処理する。サーバを立てずにルーティングを確かめたいとき用
   *
   * @internal
   */
  public function handle(Request $request): Response
  {
    return $this->router->handle($request);
  }

  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   */
  public function serve(?string $host = null, ?int $port = null): void
  {
    $host ??= self::DEFAULT_HOST;
    $port ??= self::DEFAULT_PORT;

    $server = new Server("tcp://{$host}:{$port}", new HttpProtocol($this->router));
    $server->run();
  }
}
