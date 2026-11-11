<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Routing;

use Closure;
use Throwable;
use UnexpectedValueException;
use Urassh\Phalcon\Internal\Http\BadRequestException;
use Urassh\Phalcon\Internal\Http\RequestHandler;
use Urassh\Phalcon\Internal\View\ViewException;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

/**
 * @internal
 */
final class Router implements RequestHandler
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
      return Response::text("Method Not Allowed\n", 405)
        ->withHeader('Allow', implode(', ', array_keys($allowed)));
    }

    return Response::text("Not Found\n", 404);
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
    } catch (BadRequestException) {
      return Response::text("Bad Request\n", 400);
    } catch (Throwable $e) {
      error_log("Unhandled exception in {$request->method()} {$request->path()}: {$e}");

      return Response::text("Internal Server Error\n", 500);
    }
  }

  private function render(Response $response): Response
  {
    if (!$response->needsRender()) {
      return $response;
    }

    if ($this->renderer === null) {
      throw new ViewException('Response::view() requires view.directory in the config file or App::useRenderer()');
    }

    return $response->render($this->renderer);
  }
}
