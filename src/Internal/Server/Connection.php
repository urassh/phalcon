<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server;

/**
 * @internal
 */
final class Connection
{
  public const MAX_BUFFER_SIZE = 1024 * 1024;

  private const CHUNK_SIZE = 8192;
  private const TIMEOUT_SECONDS = 5;

  /**
   * @param resource $stream
   */
  public function __construct(
    private readonly mixed $stream,
    private readonly Protocol $protocol,
  ) {
  }

  public function peerName(): string
  {
    $name = stream_socket_get_name($this->stream, true);

    return $name === false ? 'unknown' : $name;
  }

  public function handle(): void
  {
    try {
      stream_set_timeout($this->stream, self::TIMEOUT_SECONDS);

      $data = $this->read();

      $this->write($this->protocol->respond($data));
    } finally {
      $this->close();
    }
  }

  private function read(): string
  {
    $data = '';

    while (!$this->protocol->isComplete($data) && strlen($data) <= self::MAX_BUFFER_SIZE) {
      $chunk = fread($this->stream, self::CHUNK_SIZE);
      if ($chunk === false || $chunk === '') {
        break;
      }

      $data .= $chunk;
    }

    return $data;
  }

  private function write(string $data): void
  {
    fwrite($this->stream, $data);
  }

  private function close(): void
  {
    fclose($this->stream);
  }
}
