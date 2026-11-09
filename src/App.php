<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use Closure;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Internal\Config\Config;
use Urassh\Phalcon\Internal\Http\HttpProtocol;
use Urassh\Phalcon\Internal\Routing\Router;
use Urassh\Phalcon\Internal\Server\Server;
use Urassh\Phalcon\Internal\View\PhpRenderer;

final class App
{
  private const DEFAULT_HOST = '0.0.0.0';
  private const DEFAULT_PORT = 8080;

  private readonly Router $router;

  private Config $config;

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
   *
   * 値の優先順位は 引数 > 設定ファイル > デフォルト値
   */
  public function serve(?string $host = null, ?int $port = null): void
  {
    $host ??= $this->config->host() ?? self::DEFAULT_HOST;
    $port ??= $this->config->port() ?? self::DEFAULT_PORT;

    $server = new Server("tcp://{$host}:{$port}", new HttpProtocol($this->router));
    $server->run();
  }
}
