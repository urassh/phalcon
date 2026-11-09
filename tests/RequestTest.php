<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Internal\Http\BadRequestException;
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

  public function testPathStripsQueryString(): void
  {
    $this->assertSame('/users/42', Request::parse("GET /users/42?sort=asc HTTP/1.1\r\n\r\n")->path());
  }

  public function testPathOfAbsoluteFormTarget(): void
  {
    $this->assertSame('/users', Request::parse("GET http://localhost:8080/users?x=1 HTTP/1.1\r\n\r\n")->path());
    $this->assertSame('/', Request::parse("GET http://localhost:8080 HTTP/1.1\r\n\r\n")->path());
  }

  public function testQueryReturnsDecodedValueOrNull(): void
  {
    $request = Request::parse("GET /search?q=hello%20world&tags[]=a HTTP/1.1\r\n\r\n");

    $this->assertSame('hello world', $request->query('q'));
    $this->assertNull($request->query('missing'));
    $this->assertNull($request->query('tags'));
  }

  public function testHeaderIsCaseInsensitive(): void
  {
    $request = Request::parse("GET / HTTP/1.1\r\nX-Token: abc\r\nX-Token: def\r\n\r\n");

    $this->assertSame('abc', $request->header('x-token'));
    $this->assertNull($request->header('X-Missing'));
  }

  public function testParamComesFromWithParams(): void
  {
    $request = Request::parse("GET /users/42 HTTP/1.1\r\n\r\n");

    $this->assertNull($request->param('id'));
    $this->assertSame('42', $request->withParams(['id' => '42'])->param('id'));
  }

  public function testJsonDecodesObjectsAsArrays(): void
  {
    $request = Request::parse("POST / HTTP/1.1\r\nContent-Length: 15\r\n\r\n{\"name\":\"abc\"}");

    $this->assertSame(['name' => 'abc'], $request->json());
  }

  public function testJsonThrowsBadRequestOnMalformedBody(): void
  {
    $this->expectException(BadRequestException::class);

    Request::parse("POST / HTTP/1.1\r\nContent-Length: 1\r\n\r\n{")->json();
  }
}
