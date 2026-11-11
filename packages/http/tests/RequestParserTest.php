<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Http\RequestParser;

final class RequestParserTest extends TestCase
{
  public function testParsesRequestLineHeadersAndBody(): void
  {
    $request = (new RequestParser())->parse("POST /users HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\na=1");

    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/users', $request->getRequestTarget());
    $this->assertSame('/users', $request->getUri()->getPath());
    $this->assertSame('1.1', $request->getProtocolVersion());
    $this->assertSame('localhost', $request->getHeaderLine('Host'));
    $this->assertSame('a=1', (string) $request->getBody());
  }

  public function testKeepsHeaderSpellingAndRepeatedValues(): void
  {
    $request = (new RequestParser())->parse("GET / HTTP/1.1\r\nX-Token: abc\r\nx-token: def\r\n\r\n");

    $this->assertSame(['X-Token' => ['abc', 'def']], $request->getHeaders());
    $this->assertSame(['abc', 'def'], $request->getHeader('x-token'));
  }

  public function testBodyIsEmptyWithoutContentLength(): void
  {
    $request = (new RequestParser())->parse("GET / HTTP/1.1\r\nHost: localhost\r\n\r\nignored");

    $this->assertSame('', (string) $request->getBody());
  }

  public function testBodyIsCutAtContentLength(): void
  {
    $request = (new RequestParser())->parse("POST / HTTP/1.1\r\nContent-Length: 3\r\n\r\na=1extra");

    $this->assertSame('a=1', (string) $request->getBody());
  }

  public function testParsesQueryParams(): void
  {
    $request = (new RequestParser())->parse("GET /search?q=hello%20world&tags[]=a HTTP/1.1\r\n\r\n");

    $this->assertSame('/search', $request->getUri()->getPath());
    $this->assertSame(['q' => 'hello world', 'tags' => ['a']], $request->getQueryParams());
  }

  public function testAbsoluteFormTarget(): void
  {
    $request = (new RequestParser())->parse("GET http://localhost:8080/users?x=1 HTTP/1.1\r\n\r\n");

    $this->assertSame('http://localhost:8080/users?x=1', $request->getRequestTarget());
    $this->assertSame('/users', $request->getUri()->getPath());
    $this->assertSame('localhost:8080', $request->getHeaderLine('Host'));
  }

  public function testParsesRequestWithoutHeaderTerminator(): void
  {
    $request = (new RequestParser())->parse("GET / HTTP/1.1\r\nHost: localhost");

    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('localhost', $request->getHeaderLine('Host'));
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function malformedRequests(): iterable
  {
    yield 'empty' => [''];
    yield 'missing version' => ["GET /\r\n\r\n"];
    yield 'extra space' => ["GET  / HTTP/1.1\r\n\r\n"];
    yield 'unknown version' => ["GET / FOO\r\n\r\n"];
    yield 'invalid method' => ["G(T / HTTP/1.1\r\n\r\n"];
    yield 'invalid header name' => ["GET / HTTP/1.1\r\nBad Name: x\r\n\r\n"];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('malformedRequests')]
  public function testThrowsOnMalformedRequest(string $data): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new RequestParser())->parse($data);
  }
}
