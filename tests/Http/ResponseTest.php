<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Http;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Http\Response;

final class ResponseTest extends TestCase
{
  public function testToStringBuildsHttpResponseWithContentLength(): void
  {
    $response = new Response("hello\n");

    $this->assertSame(
      "HTTP/1.1 200 OK\r\n"
        . "Content-Type: text/plain\r\n"
        . "Content-Length: 6\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . "hello\n",
      $response->toString(),
    );
  }
}
