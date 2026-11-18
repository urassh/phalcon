<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * application/x-www-form-urlencoded のボディをパースし、PSR-7 の parsedBody に入れる
 *
 * PHP の `$_POST` と違ってメソッドは問わない。すでに parsedBody があれば (サーバ側で入れた場合など) そのまま通す。
 * multipart/form-data には対応しない
 *
 * @internal
 */
final class FormBody implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    if ($request->getParsedBody() !== null || !self::isForm($request->getHeaderLine('Content-Type'))) {
      return $handler->handle($request);
    }

    parse_str((string) $request->getBody(), $form);

    return $handler->handle($request->withParsedBody($form));
  }

  /**
   * `; charset=UTF-8` などのパラメータを除いたメディアタイプで判定する。メディアタイプは大文字小文字を区別しない
   */
  private static function isForm(string $contentType): bool
  {
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

    return $mediaType === 'application/x-www-form-urlencoded';
  }
}
