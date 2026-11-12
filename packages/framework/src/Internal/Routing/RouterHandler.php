<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Routing;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Request;

/**
 * PSR-7 のリクエストを Request に包んで Router に渡し、結果を PSR-7 のレスポンスに戻す
 *
 * @internal
 */
final class RouterHandler implements RequestHandlerInterface
{
  public function __construct(
    private readonly Router $router,
    private readonly ResponseFactoryInterface $factory,
  ) {
  }

  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->router->handle(new Request($request))->toPsr($this->factory);
  }
}
