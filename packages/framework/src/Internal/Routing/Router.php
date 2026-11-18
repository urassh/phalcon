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

  /** @var (Closure(HttpException, Request): mixed)|null 戻り値は呼んだあとに検証する */
  private ?Closure $errorHandler = null;

  public function useRenderer(?Renderer $renderer): void
  {
    $this->renderer = $renderer;
  }

  /**
   * @param Closure(HttpException, Request): mixed $handler
   */
  public function onError(Closure $handler): void
  {
    $this->errorHandler = $handler;
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
      return $this->error(new MethodNotAllowedException(array_keys($allowed)), $request);
    }

    return $this->error(new NotFoundException(), $request);
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
      return $this->error($e, $request);
    } catch (Throwable $e) {
      error_log("Unhandled exception in {$request->method()} {$request->path()}: {$e}");

      // 想定外の例外のメッセージはレスポンスに含めない。元の例外は onError のフックから getPrevious() で見られる
      return $this->error(new HttpException(500, previous: $e), $request);
    }
  }

  /**
   * HttpException をレスポンスにする。次の順に試す
   *
   * 1. onError() のフックが Response を返せばそれを使う
   * 2. リクエストが JSON を求めていれば `{"error": メッセージ}` の JSON
   * 3. `errors/{ステータス}` のテンプレートがあれば描画した HTML (`$status` と `$message` を渡す)
   * 4. メッセージのテキスト
   *
   * 例外のヘッダ (405 の Allow など) は、レスポンスに同名のヘッダがなければ付ける
   *
   * ミドルウェアが投げた HttpException も RouterHandler::error() からここに渡される
   */
  public function error(HttpException $e, Request $request): Response
  {
    $response = $this->customError($e, $request)
      ?? (self::wantsJson($request) ? Response::json(['error' => $e->getMessage()], $e->status()) : null)
      ?? $this->errorPage($e)
      ?? Response::text("{$e->getMessage()}\n", $e->status());

    foreach ($e->headers() as $name => $value) {
      if ($response->header($name) === null) {
        $response = $response->withHeader($name, $value);
      }
    }

    return $response;
  }

  private function customError(HttpException $e, Request $request): ?Response
  {
    if ($this->errorHandler === null) {
      return null;
    }

    try {
      $response = ($this->errorHandler)($e, $request);
      if ($response !== null && !$response instanceof Response) {
        throw new UnexpectedValueException(
          'Error handler must return ' . Response::class . ' or null, got ' . get_debug_type($response),
        );
      }

      return $response === null ? null : $this->render($response);
    } catch (Throwable $handlerError) {
      // フックが壊れていても既定の形式で応答は返す
      error_log("Error handler failed for {$request->method()} {$request->path()}: {$handlerError}");

      return null;
    }
  }

  /**
   * Accept に JSON (`application/json` や `application/problem+json` など) があり、`text/html` がなければ true
   *
   * ブラウザのページ遷移は text/html を送るので HTML のまま、fetch() や API クライアントが JSON を求めたときだけ JSON にする
   */
  private static function wantsJson(Request $request): bool
  {
    $json = false;
    foreach (explode(',', strtolower((string) $request->header('Accept'))) as $range) {
      $type = trim(explode(';', $range, 2)[0]);
      if ($type === 'text/html') {
        return false;
      }
      if ($type === 'application/json' || str_ends_with($type, '+json')) {
        $json = true;
      }
    }

    return $json;
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
