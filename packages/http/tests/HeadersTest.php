<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Http\Headers;

final class HeadersTest extends TestCase
{
  public function testNameIsCaseInsensitive(): void
  {
    $headers = Headers::parse("Content-Type: text/plain");

    $this->assertTrue($headers->has('content-type'));
    $this->assertSame('text/plain', $headers->get('CONTENT-TYPE'));
  }

  public function testTrimsWhitespaceAroundValue(): void
  {
    $headers = Headers::parse("Host: \t localhost \t");

    $this->assertSame('localhost', $headers->get('Host'));
  }

  public function testKeepsValueContainingColon(): void
  {
    $headers = Headers::parse("Host: localhost:8080");

    $this->assertSame('localhost:8080', $headers->get('Host'));
  }

  public function testKeepsAllValuesOfRepeatedHeader(): void
  {
    $headers = Headers::parse("Accept: text/html\r\naccept: application/json");

    $this->assertSame('text/html', $headers->get('Accept'));
    $this->assertSame(['text/html', 'application/json'], $headers->getAll('Accept'));
  }

  public function testReturnsNullAndEmptyListForMissingHeader(): void
  {
    $headers = Headers::parse('');

    $this->assertFalse($headers->has('Host'));
    $this->assertNull($headers->get('Host'));
    $this->assertSame([], $headers->getAll('Host'));
  }

  public function testIgnoresLinesWithoutColonOrName(): void
  {
    $headers = Headers::parse("garbage\r\n: no-name\r\nHost: localhost");

    $this->assertSame(['host' => ['localhost']], $headers->all());
  }

  public function testWithOriginalNamesKeepsFirstSpelling(): void
  {
    $headers = Headers::parse("X-Token: a\r\nx-token: b\r\nHost: localhost");

    $this->assertSame(['X-Token' => ['a', 'b'], 'Host' => ['localhost']], $headers->withOriginalNames());
  }

  public function testContentLength(): void
  {
    $this->assertSame(3, Headers::parse('Content-Length: 3')->contentLength());
    $this->assertSame(0, Headers::parse('')->contentLength());
    $this->assertSame(0, Headers::parse('Content-Length: abc')->contentLength());
    $this->assertSame(0, Headers::parse('Content-Length: -1')->contentLength());
  }
}
