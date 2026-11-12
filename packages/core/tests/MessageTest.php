<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Request;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Core\Stream;
use Urassh\Phalcon\Core\Uri;

final class MessageTest extends TestCase
{
  public function testHeaderNamesAreCaseInsensitiveAndKeepSpelling(): void
  {
    $response = (new Response())->withHeader('X-Token', 'a')->withAddedHeader('x-token', ['b', 'c']);

    $this->assertTrue($response->hasHeader('X-TOKEN'));
    $this->assertSame(['a', 'b', 'c'], $response->getHeader('x-token'));
    $this->assertSame('a, b, c', $response->getHeaderLine('X-Token'));
    $this->assertSame(['X-Token' => ['a', 'b', 'c']], $response->getHeaders());
  }

  public function testWithHeaderReplacesSpellingAndValues(): void
  {
    $response = (new Response(headers: ['content-type' => 'text/plain']))->withHeader('Content-Type', 'text/html');

    $this->assertSame(['Content-Type' => ['text/html']], $response->getHeaders());
  }

  public function testWithoutHeaderIsCaseInsensitive(): void
  {
    $response = (new Response(headers: ['X-A' => '1', 'X-B' => '2']))->withoutHeader('x-a');

    $this->assertFalse($response->hasHeader('X-A'));
    $this->assertSame([], $response->getHeader('X-A'));
    $this->assertSame('', $response->getHeaderLine('X-A'));
    $this->assertSame(['X-B' => ['2']], $response->getHeaders());
  }

  public function testTrimsHeaderValues(): void
  {
    $this->assertSame(['a'], (new Response())->withHeader('X', " \ta\t ")->getHeader('X'));
  }

  public function testWithMethodsAreImmutable(): void
  {
    $original = new Response();
    $original->withHeader('X', '1');
    $original->withStatus(404);
    $original->withProtocolVersion('1.0');
    $original->withBody(new Stream('x'));

    $this->assertFalse($original->hasHeader('X'));
    $this->assertSame(200, $original->getStatusCode());
    $this->assertSame('1.1', $original->getProtocolVersion());
    $this->assertSame('', (string) $original->getBody());
  }

  /**
   * @return iterable<string, array{string, mixed}>
   */
  public static function invalidHeaders(): iterable
  {
    yield 'space in name' => ['Bad Name', 'x'];
    yield 'empty name' => ['', 'x'];
    yield 'CRLF in value' => ['X', "a\r\nInjected: 1"];
    yield 'empty array' => ['X', []];
    yield 'non-string value' => ['X', [null]];
  }

  #[DataProvider('invalidHeaders')]
  public function testThrowsOnInvalidHeader(string $name, mixed $value): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new Response())->withHeader($name, $value);
  }

  public function testResponseStatusAndReasonPhrase(): void
  {
    $response = new Response(404);

    $this->assertSame('Not Found', $response->getReasonPhrase());
    $this->assertSame('Custom', $response->withStatus(201, 'Custom')->getReasonPhrase());
    $this->assertSame('', $response->withStatus(299)->getReasonPhrase());
  }

  public function testThrowsOnInvalidStatus(): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new Response())->withStatus(600);
  }

  public function testRequestTargetFromUri(): void
  {
    $this->assertSame('/a?b=1', (new Request('GET', 'http://example.com/a?b=1'))->getRequestTarget());
    $this->assertSame('/', (new Request('GET', 'http://example.com'))->getRequestTarget());
    $this->assertSame('*', (new Request('OPTIONS', '/'))->withRequestTarget('*')->getRequestTarget());
  }

  public function testHostHeaderFollowsUri(): void
  {
    $request = new Request('GET', 'http://example.com:8080/', ['Accept' => 'text/html']);

    $this->assertSame(['Host' => ['example.com:8080'], 'Accept' => ['text/html']], $request->getHeaders());
    $this->assertSame('example.org', $request->withUri(new Uri('http://example.org/'))->getHeaderLine('Host'));
    $this->assertSame(
      'example.com:8080',
      $request->withUri(new Uri('http://example.org/'), preserveHost: true)->getHeaderLine('Host'),
    );
  }

  public function testExplicitHostHeaderIsKept(): void
  {
    $this->assertSame('other', (new Request('GET', 'http://example.com/', ['Host' => 'other']))->getHeaderLine('Host'));
  }

  public function testThrowsOnInvalidMethod(): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new Request('GET', '/'))->withMethod('BAD METHOD');
  }
}
