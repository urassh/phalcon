<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\ValueObject;

final class Response
{
  private const REASON_PHRASES = [
    200 => 'OK',
    400 => 'Bad Request',
  ];

  public function __construct(
    private readonly string $body,
    private readonly int $status = 200,
  ) {
  }

  public function toString(): string
  {
    $reason = self::REASON_PHRASES[$this->status] ?? '';

    return "HTTP/1.1 {$this->status} {$reason}\r\n"
      . "Content-Type: text/plain\r\n"
      . 'Content-Length: ' . strlen($this->body) . "\r\n"
      . "Connection: close\r\n"
      . "\r\n"
      . $this->body;
  }
}
