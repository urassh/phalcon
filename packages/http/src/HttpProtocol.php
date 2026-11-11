<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Http;

use InvalidArgumentException;
use Urassh\Phalcon\Internal\Server\Protocol;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

/**
 * @internal
 */
final class HttpProtocol implements Protocol
{
  public function __construct(
    private readonly RequestHandler $handler,
  ) {
  }

  public function isComplete(string $data): bool
  {
    $headerEnd = strpos($data, Request::HEADER_TERMINATOR);
    if ($headerEnd === false) {
      return false;
    }

    $bodyOffset = $headerEnd + strlen(Request::HEADER_TERMINATOR);
    [, $headerLines] = Request::splitHead(substr($data, 0, $headerEnd));
    $contentLength = Headers::parse($headerLines)->contentLength();

    return strlen($data) >= $bodyOffset + $contentLength;
  }

  public function respond(string $data): string
  {
    try {
      $request = Request::parse($data);
    } catch (InvalidArgumentException) {
      return Response::text("Bad Request\n", 400)->toString();
    }

    return $this->handler->handle($request)->toString();
  }
}
