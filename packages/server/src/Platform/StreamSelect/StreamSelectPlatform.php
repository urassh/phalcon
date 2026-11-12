<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Platform\StreamSelect;

use Urassh\Phalcon\Server\Port\ListeningSocket;
use Urassh\Phalcon\Server\Port\Platform;
use Urassh\Phalcon\Server\Port\Poller;
use Urassh\Phalcon\Server\Port\SocketException;

/**
 * PHP のストリームと stream_select による、どの OS でも動く実装
 *
 * select の制約で、同時に扱える接続は 1024 本程度まで
 *
 * @internal
 */
final class StreamSelectPlatform implements Platform
{
  private readonly Streams $streams;

  public function __construct()
  {
    $this->streams = new Streams();
  }

  public function listen(string $host, int $port): ListeningSocket
  {
    $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
    if ($server === false) {
      throw new SocketException("Failed to listen on {$host}:{$port}: {$errstr} ({$errno})", $errno ?? 0);
    }

    stream_set_blocking($server, false);

    return new StreamListeningSocket($this->streams, $server);
  }

  public function createPoller(): Poller
  {
    return new SelectPoller($this->streams);
  }
}
