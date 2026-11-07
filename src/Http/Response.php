<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

final class Response
{
  public function __construct(
    private readonly string $body,
  ) {
  }

  public function toString(): string
  {
    return "HTTP/1.1 200 OK\r\n"
      . "Content-Type: text/plain\r\n"
      . 'Content-Length: ' . strlen($this->body) . "\r\n"
      . "Connection: close\r\n"
      . "\r\n"
      . $this->body;
  }
}
