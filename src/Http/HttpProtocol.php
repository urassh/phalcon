<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Urassh\Phalcon\Server\Protocol;

final class HttpProtocol implements Protocol
{
  private const HEADER_TERMINATOR = "\r\n\r\n";

  public function __construct(
    private readonly RequestHandler $handler,
  ) {
  }

  public function isComplete(string $data): bool
  {
    $headerEnd = strpos($data, self::HEADER_TERMINATOR);
    if ($headerEnd === false) {
      return false;
    }

    $bodyOffset = $headerEnd + strlen(self::HEADER_TERMINATOR);
    $contentLength = $this->contentLength(substr($data, 0, $headerEnd));

    return strlen($data) >= $bodyOffset + $contentLength;
  }

  public function respond(string $data): string
  {
    return $this->handler->handle($data)->toString();
  }

  private function contentLength(string $header): int
  {
    if (preg_match('/^Content-Length:[ \t]*(\d+)[ \t]*\r?$/im', $header, $matches) !== 1) {
      return 0;
    }

    return (int) $matches[1];
  }
}
