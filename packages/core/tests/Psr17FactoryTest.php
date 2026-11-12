<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Core\Psr17Factory;

final class Psr17FactoryTest extends TestCase
{
  public function testCreateResponse(): void
  {
    $response = (new Psr17Factory())->createResponse(404);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Not Found', $response->getReasonPhrase());
    $this->assertTrue($response->getBody()->isWritable());
  }

  public function testCreateServerRequestFillsQueryParams(): void
  {
    $request = (new Psr17Factory())->createServerRequest('GET', '/search?q=a%20b', ['REMOTE_ADDR' => '::1']);

    $this->assertSame('/search', $request->getUri()->getPath());
    $this->assertSame(['q' => 'a b'], $request->getQueryParams());
    $this->assertSame(['REMOTE_ADDR' => '::1'], $request->getServerParams());
  }

  public function testCreateStreamFromFileAndResource(): void
  {
    $factory = new Psr17Factory();
    $path = tempnam(sys_get_temp_dir(), 'phalcon');
    $this->assertIsString($path);
    file_put_contents($path, 'contents');

    try {
      $this->assertSame('contents', (string) $factory->createStreamFromFile($path));
    } finally {
      unlink($path);
    }

    $resource = fopen('php://memory', 'r+');
    $this->assertIsResource($resource);
    fwrite($resource, 'memory');
    rewind($resource);
    $this->assertSame('memory', (string) $factory->createStreamFromResource($resource));
  }

  public function testCreateStreamFromMissingFileThrows(): void
  {
    $this->expectException(RuntimeException::class);

    (new Psr17Factory())->createStreamFromFile('/nonexistent/file');
  }

  public function testCreateUri(): void
  {
    $this->assertSame('http://example.com/a', (string) (new Psr17Factory())->createUri('http://example.com/a'));
  }
}
