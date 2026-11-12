<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ミドルウェアを先頭から順に通し、最後にハンドラへ渡す
 *
 * @internal
 */
final class Pipeline implements RequestHandlerInterface
{
  /**
   * @param list<MiddlewareInterface> $middleware 先頭が最も外側
   */
  public function __construct(
    private readonly array $middleware,
    private readonly RequestHandlerInterface $handler,
  ) {
  }

  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    if ($this->middleware === []) {
      return $this->handler->handle($request);
    }

    $rest = $this->middleware;
    $first = array_shift($rest);

    return $first->process($request, new self($rest, $this->handler));
  }
}
