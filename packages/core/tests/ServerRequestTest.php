<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Urassh\Phalcon\Core\ServerRequest;
use Urassh\Phalcon\Core\Stream;
use Urassh\Phalcon\Core\UploadedFile;

final class ServerRequestTest extends TestCase
{
  public function testAttributes(): void
  {
    $original = new ServerRequest('GET', '/');
    $request = $original->withAttribute('id', '42')->withAttribute('null', null);

    $this->assertSame([], $original->getAttributes());
    $this->assertSame('42', $request->getAttribute('id'));
    $this->assertNull($request->getAttribute('null', 'default'));
    $this->assertSame('default', $request->getAttribute('missing', 'default'));
    $this->assertSame(['null' => null], $request->withoutAttribute('id')->getAttributes());
  }

  public function testParamsAreImmutable(): void
  {
    $original = new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => '127.0.0.1']);
    $request = $original
      ->withQueryParams(['q' => 'php'])
      ->withCookieParams(['session' => 'abc'])
      ->withParsedBody(['name' => 'x']);

    $this->assertSame([], $original->getQueryParams());
    $this->assertSame([], $original->getCookieParams());
    $this->assertNull($original->getParsedBody());
    $this->assertSame(['q' => 'php'], $request->getQueryParams());
    $this->assertSame(['session' => 'abc'], $request->getCookieParams());
    $this->assertSame(['name' => 'x'], $request->getParsedBody());
    $this->assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
  }

  public function testParsedBodyAcceptsObject(): void
  {
    $body = new stdClass();

    $this->assertSame($body, (new ServerRequest('POST', '/'))->withParsedBody($body)->getParsedBody());
  }

  public function testThrowsOnInvalidParsedBody(): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new ServerRequest('POST', '/'))->withParsedBody('string');
  }

  public function testUploadedFilesMustBeATreeOfUploadedFile(): void
  {
    $file = new UploadedFile(new Stream('data'), 4);
    $request = (new ServerRequest('POST', '/'))->withUploadedFiles(['docs' => [$file]]);

    $this->assertSame(['docs' => [$file]], $request->getUploadedFiles());

    $this->expectException(InvalidArgumentException::class);
    $request->withUploadedFiles(['docs' => ['not a file']]);
  }
}
