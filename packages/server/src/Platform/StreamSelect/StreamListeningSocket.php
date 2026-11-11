<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Platform\StreamSelect;

use Urassh\Phalcon\Server\Port\ListeningSocket;
use Urassh\Phalcon\Server\Port\Socket;

/**
 * @internal
 */
final class StreamListeningSocket implements ListeningSocket
{
  private readonly int $id;

  /**
   * @param resource $server
   */
  public function __construct(
    private readonly Streams $streams,
    private readonly mixed $server,
  ) {
    $this->id = $streams->register($server);
  }

  public function fd(): int
  {
    return $this->id;
  }

  public function localAddress(): string
  {
    $name = stream_socket_get_name($this->server, false);

    return $name === false ? 'unknown' : $name;
  }

  public function accept(): ?Socket
  {
    // 受け付け待ちがないときも false (と警告) になるので、エラーとは区別できない
    $stream = @stream_socket_accept($this->server, 0);
    if ($stream === false) {
      $this->streams->armRead($this->id);

      return null;
    }

    return new StreamSocket($this->streams, $stream);
  }

  public function close(): void
  {
    $this->streams->unregister($this->id);
    fclose($this->server);
  }
}
