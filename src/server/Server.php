<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

use RuntimeException;

final class Server
{
  public function __construct(
    private readonly string $host,
  ) {
  }

  public function run(): void
  {
    $socket = stream_socket_server(
      $this->host,
      $errno,
      $errstr
    );

    if ($socket === false) {
      throw new RuntimeException("Failed to listen on {$this->host}: {$errstr} ({$errno})");
    }

    echo "Listening on {$this->host}\n";

    do {
      $stream = stream_socket_accept($socket, -1);
      if ($stream) {
        $connection = new Connection($stream);
        echo 'Accepted connection from ' . $connection->peerName() . "\n";
        $connection->write("Hello from the server!\n");
        $connection->close();
      }

    } while ($stream);
  }
}
