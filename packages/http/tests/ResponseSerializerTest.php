<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Http\ResponseSerializer;

final class ResponseSerializerTest extends TestCase
{
  public function testBuildsHttpResponseWithContentLength(): void
  {
    $response = new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], "hello\n");

    $this->assertSame(
      "HTTP/1.1 200 OK\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Length: 6\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . "hello\n",
      ResponseSerializer::serialize($response),
    );
  }

  public function testUsesStatusAndReasonPhrase(): void
  {
    $this->assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", ResponseSerializer::serialize(new Response(400)));
    $this->assertStringStartsWith("HTTP/1.1 299 \r\n", ResponseSerializer::serialize(new Response(299)));
    $this->assertStringStartsWith(
      "HTTP/1.1 200 Fine\r\n",
      ResponseSerializer::serialize((new Response())->withStatus(200, 'Fine')),
    );
  }

  public function testManagedHeadersCannotBeOverridden(): void
  {
    $response = (new Response(body: 'abc'))
      ->withHeader('Content-Length', '999')
      ->withHeader('Connection', 'keep-alive');

    $this->assertSame(
      "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nConnection: close\r\n\r\nabc",
      ResponseSerializer::serialize($response),
    );
  }

  public function testJoinsMultipleValuesExceptSetCookie(): void
  {
    $response = (new Response())
      ->withHeader('Vary', ['Accept', 'Origin'])
      ->withHeader('Set-Cookie', ['a=1', 'b=2']);

    $this->assertSame(
      "HTTP/1.1 200 OK\r\nVary: Accept, Origin\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n"
        . "Content-Length: 0\r\nConnection: close\r\n\r\n",
      ResponseSerializer::serialize($response),
    );
  }
}
