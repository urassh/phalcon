<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

final class Connection
{
  /**
   * @param resource $stream
   */
  public function __construct(
    private readonly mixed $stream,
  ) {
  }

  public function peerName(): string
  {
    $name = stream_socket_get_name($this->stream, true);

    return $name === false ? 'unknown' : $name;
  }

  public function write(string $data): void
  {
    fwrite($this->stream, $data);
  }

  public function close(): void
  {
    fclose($this->stream);
  }
}
