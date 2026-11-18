<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTML のフォームは GET / POST しか送れないので、POST のフォームに `_method` があればそのメソッドとしてルーティングさせる
 *
 * 上書きできるのは POST から PUT / PATCH / DELETE への変換だけ。GET などへの変換や、POST 以外からの変換は無視する。
 * FormBody がパースしたあとに置き、CSRF の判定 (CrossOriginProtection) は上書き前の POST に対して済ませておく
 *
 * @internal
 */
final class MethodOverride implements MiddlewareInterface
{
  private const FIELD = '_method';

  private const METHODS = ['PUT', 'PATCH', 'DELETE'];

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $form = $request->getParsedBody();
    if ($request->getMethod() !== 'POST' || !is_array($form) || !is_string($form[self::FIELD] ?? null)) {
      return $handler->handle($request);
    }

    $method = strtoupper($form[self::FIELD]);

    return $handler->handle(
      in_array($method, self::METHODS, true) ? $request->withMethod($method) : $request,
    );
  }
}
