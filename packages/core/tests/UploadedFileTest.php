<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Core\Stream;
use Urassh\Phalcon\Core\UploadedFile;

final class UploadedFileTest extends TestCase
{
  public function testMoveToWritesContentsOnce(): void
  {
    $file = new UploadedFile(new Stream('data'), 4, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
    $path = tempnam(sys_get_temp_dir(), 'phalcon');
    $this->assertIsString($path);

    try {
      $file->moveTo($path);
      $this->assertSame('data', file_get_contents($path));
    } finally {
      unlink($path);
    }

    $this->assertSame(4, $file->getSize());
    $this->assertSame('a.txt', $file->getClientFilename());
    $this->assertSame('text/plain', $file->getClientMediaType());

    $this->expectException(RuntimeException::class);
    $file->getStream();
  }

  public function testStreamIsUnavailableOnUploadError(): void
  {
    $file = new UploadedFile(new Stream(), null, UPLOAD_ERR_NO_FILE);

    $this->assertSame(UPLOAD_ERR_NO_FILE, $file->getError());

    $this->expectException(RuntimeException::class);
    $file->getStream();
  }

  public function testThrowsOnInvalidErrorCode(): void
  {
    $this->expectException(InvalidArgumentException::class);

    new UploadedFile(new Stream(), null, 99);
  }
}
