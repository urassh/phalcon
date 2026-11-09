<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Request;

final class RequestTest extends TestCase
{
  public function testParsesRequestLineHeadersAndBody(): void
  {
    $request = Request::parse("POST /users HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\na=1");

    $this->assertSame('POST', $request->method());
    $this->assertSame('/users', $request->target());
    $this->assertSame('HTTP/1.1', $request->version());
    $this->assertSame('localhost', $request->headers()->get('Host'));
    $this->assertSame('a=1', $request->body());
  }

  public function testBodyIsEmptyWithoutContentLength(): void
  {
    $request = Request::parse("GET / HTTP/1.1\r\nHost: localhost\r\n\r\nignored");

    $this->assertSame('', $request->body());
  }

  public function testBodyIsCutAtContentLength(): void
  {
    $request = Request::parse("POST / HTTP/1.1\r\nContent-Length: 3\r\n\r\na=1extra");

    $this->assertSame('a=1', $request->body());
  }

  public function testAbsoluteFormTargetIsNotTreatedAsHeader(): void
  {
    $request = Request::parse("GET http://localhost:8080/ HTTP/1.1\r\n\r\n");

    $this->assertSame('http://localhost:8080/', $request->target());
    $this->assertSame([], $request->headers()->all());
  }

  public function testParsesRequestWithoutHeaderTerminator(): void
  {
    $request = Request::parse("GET / HTTP/1.1\r\nHost: localhost");

    $this->assertSame('GET', $request->method());
    $this->assertSame('localhost', $request->headers()->get('Host'));
  }

  public function testThrowsOnMalformedRequestLine(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Request::parse("GET /\r\n\r\n");
  }

  public function testThrowsOnEmptyData(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Request::parse('');
  }
}
