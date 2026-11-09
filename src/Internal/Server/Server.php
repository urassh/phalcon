<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server;

use RuntimeException;

/**
 * 1 接続ずつブロッキングで処理するサーバ
 *
 * Platform のアダプタができたら EventLoop に置き換える
 *
 * @internal
 */
final class Server
{
  private const CHUNK_SIZE = 8192;
  private const TIMEOUT_SECONDS = 5;

  public function __construct(
    private readonly string $host,
    private readonly Protocol $protocol,
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
        $name = stream_socket_get_name($stream, true);
        echo 'Accepted connection from ' . ($name === false ? 'unknown' : $name) . "\n";
        $this->handle($stream);
      }

    } while ($stream);
  }

  /**
   * @param resource $stream
   */
  private function handle(mixed $stream): void
  {
    try {
      stream_set_timeout($stream, self::TIMEOUT_SECONDS);

      $data = '';
      while (!$this->protocol->isComplete($data) && strlen($data) <= Connection::MAX_BUFFER_SIZE) {
        $chunk = fread($stream, self::CHUNK_SIZE);
        if ($chunk === false || $chunk === '') {
          break;
        }

        $data .= $chunk;
      }

      fwrite($stream, $this->protocol->respond($data));
    } finally {
      fclose($stream);
    }
  }
}
