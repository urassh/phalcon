<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Uri;

final class UriTest extends TestCase
{
  public function testParsesAbsoluteForm(): void
  {
    $uri = new Uri('HTTPS://user:pass@Example.com:8443/a/b?x=1#frag');

    $this->assertSame('https', $uri->getScheme());
    $this->assertSame('user:pass', $uri->getUserInfo());
    $this->assertSame('example.com', $uri->getHost());
    $this->assertSame(8443, $uri->getPort());
    $this->assertSame('user:pass@example.com:8443', $uri->getAuthority());
    $this->assertSame('/a/b', $uri->getPath());
    $this->assertSame('x=1', $uri->getQuery());
    $this->assertSame('frag', $uri->getFragment());
    $this->assertSame('https://user:pass@example.com:8443/a/b?x=1#frag', (string) $uri);
  }

  public function testParsesOriginForm(): void
  {
    $uri = new Uri('/users/42?sort=asc');

    $this->assertSame('', $uri->getHost());
    $this->assertSame('/users/42', $uri->getPath());
    $this->assertSame('sort=asc', $uri->getQuery());
    $this->assertSame('/users/42?sort=asc', (string) $uri);
  }

  public function testDefaultPortIsOmitted(): void
  {
    $this->assertNull((new Uri('http://localhost:80/'))->getPort());
    $this->assertSame(80, (new Uri('https://localhost:80/'))->getPort());
    $this->assertNull((new Uri('http://localhost:443/'))->withScheme('https')->getPort());
  }

  public function testEncodesInvalidCharactersOnly(): void
  {
    $uri = (new Uri())->withPath('/a b/%20/%zz')->withQuery('q=a b');

    $this->assertSame('/a%20b/%20/%25zz', $uri->getPath());
    $this->assertSame('q=a%20b', $uri->getQuery());
  }

  public function testWithMethodsReturnNewInstance(): void
  {
    $original = new Uri('http://example.com/');
    $modified = $original->withHost('example.org')->withPort(8080)->withUserInfo('u', 'p');

    $this->assertSame('http://example.com/', (string) $original);
    $this->assertSame('http://u:p@example.org:8080/', (string) $modified);
  }

  public function testPathIsAdjustedWhenRendering(): void
  {
    $this->assertSame('http://example.com/path', (string) (new Uri('http://example.com'))->withPath('path'));
    $this->assertSame('/path', (string) (new Uri())->withPath('//path'));
  }

  public function testThrowsOnInvalidPort(): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new Uri())->withPort(65536);
  }

  public function testThrowsOnUnparsableUri(): void
  {
    $this->expectException(InvalidArgumentException::class);

    new Uri('http:///example.com');
  }
}
