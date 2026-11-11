<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * ServerRequestInterface の型を満たすための最小限の実装
 *
 * サーバは multipart/form-data をパースしないので、この型のインスタンスを作るのは利用者だけ
 */
final class UploadedFile implements UploadedFileInterface
{
  private bool $moved = false;

  public function __construct(
    private readonly StreamInterface $stream,
    private readonly ?int $size = null,
    private readonly int $error = UPLOAD_ERR_OK,
    private readonly ?string $clientFilename = null,
    private readonly ?string $clientMediaType = null,
  ) {
    if ($error < UPLOAD_ERR_OK || $error > UPLOAD_ERR_EXTENSION) {
      throw new InvalidArgumentException("Invalid upload error code: {$error}");
    }
  }

  public function getStream(): StreamInterface
  {
    $this->assertMovable();

    return $this->stream;
  }

  public function moveTo(string $targetPath): void
  {
    $this->assertMovable();
    if ($targetPath === '') {
      throw new InvalidArgumentException('Target path must not be empty');
    }

    if ($this->stream->isSeekable()) {
      $this->stream->rewind();
    }
    if (@file_put_contents($targetPath, $this->stream->getContents()) === false) {
      throw new RuntimeException("Unable to write to {$targetPath}");
    }

    $this->moved = true;
  }

  public function getSize(): ?int
  {
    return $this->size;
  }

  public function getError(): int
  {
    return $this->error;
  }

  public function getClientFilename(): ?string
  {
    return $this->clientFilename;
  }

  public function getClientMediaType(): ?string
  {
    return $this->clientMediaType;
  }

  private function assertMovable(): void
  {
    if ($this->error !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Cannot retrieve stream due to upload error');
    }
    if ($this->moved) {
      throw new RuntimeException('Uploaded file has already been moved');
    }
  }
}
