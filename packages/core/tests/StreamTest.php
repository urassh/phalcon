<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Core\Stream;

final class StreamTest extends TestCase
{
  public function testReadsSequentially(): void
  {
    $stream = new Stream('hello world');

    $this->assertSame(11, $stream->getSize());
    $this->assertSame('hello', $stream->read(5));
    $this->assertSame(5, $stream->tell());
    $this->assertSame(' world', $stream->getContents());
    $this->assertTrue($stream->eof());
    $this->assertSame('hello world', (string) $stream);
  }

  public function testSeekAndWrite(): void
  {
    $stream = new Stream('hello');
    $stream->seek(0, SEEK_END);
    $stream->write(' world');
    $stream->seek(-5, SEEK_END);

    $this->assertSame('world', $stream->getContents());

    $stream->rewind();
    $stream->write('J');
    $this->assertSame('Jello world', (string) $stream);
  }

  public function testWritePastEndPadsWithNul(): void
  {
    $stream = new Stream('ab');
    $stream->seek(4);
    $stream->write('c');

    $this->assertSame("ab\0\0c", (string) $stream);
  }

  public function testSeekBeforeStartThrows(): void
  {
    $this->expectException(RuntimeException::class);

    (new Stream('abc'))->seek(-1);
  }

  public function testDetachedStreamIsUnusable(): void
  {
    $stream = new Stream('abc');

    $this->assertNull($stream->detach());
    $this->assertNull($stream->getSize());
    $this->assertSame('', (string) $stream);
    $this->assertFalse($stream->isReadable());
    $this->assertSame([], $stream->getMetadata());

    $this->expectException(RuntimeException::class);
    $stream->read(1);
  }
}
