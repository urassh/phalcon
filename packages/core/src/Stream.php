<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * 文字列を保持するインメモリのストリーム
 *
 * リクエストもレスポンスも全体をバッファしてから扱うため、ストリーミングには対応しない
 */
final class Stream implements StreamInterface
{
  private int $position = 0;
  private bool $detached = false;

  public function __construct(
    private string $contents = '',
  ) {
  }

  public function __toString(): string
  {
    return $this->detached ? '' : $this->contents;
  }

  public function close(): void
  {
    $this->detach();
  }

  public function detach()
  {
    $this->detached = true;
    $this->contents = '';
    $this->position = 0;

    // 元になるリソースを持たないので、常に null を返す
    return null;
  }

  public function getSize(): ?int
  {
    return $this->detached ? null : strlen($this->contents);
  }

  public function tell(): int
  {
    $this->assertAttached();

    return $this->position;
  }

  public function eof(): bool
  {
    return $this->detached || $this->position >= strlen($this->contents);
  }

  public function isSeekable(): bool
  {
    return !$this->detached;
  }

  public function seek(int $offset, int $whence = SEEK_SET): void
  {
    $this->assertAttached();

    $position = match ($whence) {
      SEEK_SET => $offset,
      SEEK_CUR => $this->position + $offset,
      SEEK_END => strlen($this->contents) + $offset,
      default => throw new RuntimeException("Invalid whence: {$whence}"),
    };
    if ($position < 0) {
      throw new RuntimeException("Unable to seek to position {$position}");
    }

    $this->position = $position;
  }

  public function rewind(): void
  {
    $this->seek(0);
  }

  public function isWritable(): bool
  {
    return !$this->detached;
  }

  public function write(string $string): int
  {
    $this->assertAttached();

    // 末尾より先にシークしていた場合は、間を NUL で埋める
    $contents = str_pad($this->contents, $this->position, "\0");
    $this->contents = substr($contents, 0, $this->position)
      . $string
      . substr($contents, $this->position + strlen($string));
    $this->position += strlen($string);

    return strlen($string);
  }

  public function isReadable(): bool
  {
    return !$this->detached;
  }

  public function read(int $length): string
  {
    $this->assertAttached();
    if ($length < 0) {
      throw new RuntimeException('Length must be non-negative');
    }

    $chunk = substr($this->contents, $this->position, $length);
    $this->position += strlen($chunk);

    return $chunk;
  }

  public function getContents(): string
  {
    $this->assertAttached();

    $rest = substr($this->contents, $this->position);
    $this->position = strlen($this->contents);

    return $rest;
  }

  public function getMetadata(?string $key = null)
  {
    $metadata = $this->detached ? [] : [
      'seekable' => true,
      'mode' => 'r+',
      'uri' => 'php://memory',
    ];

    return $key === null ? $metadata : ($metadata[$key] ?? null);
  }

  private function assertAttached(): void
  {
    if ($this->detached) {
      throw new RuntimeException('Stream is detached');
    }
  }
}
