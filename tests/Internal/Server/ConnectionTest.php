<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Internal\Server\Connection;

final class ConnectionTest extends TestCase
{
  public function testPassesCompleteDataToProtocolAndWritesResponseThenCloses(): void
  {
    [$server, $client] = $this->createSocketPair();
    $protocol = new FakeProtocol();
    fwrite($client, 'helloEND');

    (new Connection($server, $protocol))->handle();

    $this->assertSame('helloEND', $protocol->received);
    // close されていれば EOF まで読み切れる
    $this->assertSame('response', stream_get_contents($client));
    fclose($client);
  }

  public function testPassesPartialDataToProtocolWhenClientStopsSending(): void
  {
    [$server, $client] = $this->createSocketPair();
    $protocol = new FakeProtocol();
    fwrite($client, 'hello');
    stream_socket_shutdown($client, STREAM_SHUT_WR);

    (new Connection($server, $protocol))->handle();

    $this->assertSame('hello', $protocol->received);
    fclose($client);
  }

  public function testStopsReadingOnceBufferExceedsMaxSize(): void
  {
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
      throw new RuntimeException('Failed to open stream');
    }
    $size = Connection::MAX_BUFFER_SIZE * 2;
    fwrite($stream, str_repeat('a', $size));
    rewind($stream);
    $protocol = new FakeProtocol();

    (new Connection($stream, $protocol))->handle();

    $this->assertGreaterThan(Connection::MAX_BUFFER_SIZE, strlen((string) $protocol->received));
    $this->assertLessThan($size, strlen((string) $protocol->received));
  }

  /**
   * @return array{resource, resource}
   */
  private function createSocketPair(): array
  {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
      throw new RuntimeException('Failed to create socket pair');
    }

    return [$pair[0], $pair[1]];
  }
}
