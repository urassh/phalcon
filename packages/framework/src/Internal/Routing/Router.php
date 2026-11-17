<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Routing;

use Closure;
use Throwable;
use UnexpectedValueException;
use Urassh\Phalcon\Exception\HttpException;
use Urassh\Phalcon\Exception\MethodNotAllowedException;
use Urassh\Phalcon\Exception\NotFoundException;
use Urassh\Phalcon\Internal\View\ViewException;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

/**
 * @internal
 */
final class Router
{
  /** @var list<Route> */
  private array $routes = [];

  private ?Renderer $renderer = null;

  public function useRenderer(?Renderer $renderer): void
  {
    $this->renderer = $renderer;
  }

  public function add(string $method, string $path, Closure $handler): void
  {
    $this->routes[] = new Route(strtoupper($method), $path, $handler);
  }

  public function handle(Request $request): Response
  {
    $path = $request->path();
    $allowed = [];

    // 先に登録したルートを優先する
    foreach ($this->routes as $route) {
      $params = $route->match($path);
      if ($params === null) {
        continue;
      }

      if ($route->method() !== $request->method()) {
        $allowed[$route->method()] = true;
        continue;
      }

      return $this->invoke($route->handler(), $request->withParams($params));
    }

    if ($allowed !== []) {
      return $this->error(new MethodNotAllowedException(array_keys($allowed)));
    }

    return $this->error(new NotFoundException());
  }

  private function invoke(Closure $handler, Request $request): Response
  {
    try {
      $response = $handler($request);
      if (!$response instanceof Response) {
        throw new UnexpectedValueException(
          'Handler must return ' . Response::class . ', got ' . get_debug_type($response),
        );
      }

      return $this->render($response);
    } catch (HttpException $e) {
      return $this->error($e);
    } catch (Throwable $e) {
      error_log("Unhandled exception in {$request->method()} {$request->path()}: {$e}");

      // 想定外の例外のメッセージはレスポンスに含めない
      return $this->error(new HttpException(500));
    }
  }

  /**
   * HttpException をレスポンスにする。`errors/{ステータス}` のテンプレートがあれば描画し、なければテキストで返す
   *
   * テンプレートには `$status` と `$message` を渡す
   */
  private function error(HttpException $e): Response
  {
    $response = $this->errorPage($e) ?? Response::text("{$e->getMessage()}\n", $e->status());
    foreach ($e->headers() as $name => $value) {
      $response = $response->withHeader($name, $value);
    }

    return $response;
  }

  private function errorPage(HttpException $e): ?Response
  {
    $name = "errors/{$e->status()}";
    if ($this->renderer === null || !$this->renderer->exists($name)) {
      return null;
    }

    try {
      return Response::view($name, ['status' => $e->status(), 'message' => $e->getMessage()], $e->status())
        ->render($this->renderer);
    } catch (Throwable $renderError) {
      // エラーページ自体が壊れていてもテキストで応答は返す
      error_log("Failed to render error page {$name}: {$renderError}");

      return null;
    }
  }

  private function render(Response $response): Response
  {
    if (!$response->needsRender()) {
      return $response;
    }

    if ($this->renderer === null) {
      throw new ViewException('Response::view() requires view.directory in the config file or Phalcon::useRenderer()');
    }

    return $response->render($this->renderer);
  }
}
