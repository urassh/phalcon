<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\StreamSelect;

use Urassh\Phalcon\Internal\Server\Port\Socket;
use Urassh\Phalcon\Internal\Server\Port\SocketException;

/**
 * @internal
 */
final class StreamSocket implements Socket
{
  private readonly int $id;

  /**
   * @param resource $stream
   */
  public function __construct(
    private readonly Streams $streams,
    private readonly mixed $stream,
  ) {
    stream_set_blocking($stream, false);
    // PHP 側の読み込みバッファにデータが残ると stream_select から見えなくなるので、バッファリングは自前でやる
    stream_set_read_buffer($stream, 0);
    stream_set_write_buffer($stream, 0);
    $this->id = $streams->register($stream);
  }

  public function fd(): int
  {
    return $this->id;
  }

  public function read(int $maxBytes): ?string
  {
    $data = @fread($this->stream, max(1, $maxBytes));
    if ($data === false) {
      throw new SocketException('Failed to read from stream');
    }
    if ($data !== '') {
      return $data;
    }
    if (feof($this->stream)) {
      return '';
    }

    $this->streams->armRead($this->id);

    return null;
  }

  public function write(string $data): int
  {
    $written = @fwrite($this->stream, $data);
    if ($written === false) {
      throw new SocketException('Failed to write to stream');
    }
    if ($written === 0 && $data !== '') {
      $this->streams->armWrite($this->id);
    }

    return $written;
  }

  public function close(): void
  {
    $this->streams->unregister($this->id);
    fclose($this->stream);
  }
}
