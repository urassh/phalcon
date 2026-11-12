<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Server\Connection;

final class ConnectionTest extends TestCase
{
  public function testReadsUntilWouldBlockThenRespondsWhenComplete(): void
  {
    $socket = new FakeSocket();
    $socket->incoming = ['hel', 'lo', 'END'];
    $protocol = new FakeProtocol();
    $connection = new Connection($socket, $protocol, 1000);

    $connection->onReadable();

    $this->assertSame('helloEND', $protocol->received);
    $this->assertSame('response', $socket->written);
    $this->assertTrue($connection->isFinished());
  }

  public function testWaitsForNextNotificationWhenDataIsIncomplete(): void
  {
    $socket = new FakeSocket();
    $socket->incoming = ['hello'];
    $protocol = new FakeProtocol();
    $connection = new Connection($socket, $protocol, 1000);

    $connection->onReadable();

    $this->assertNull($protocol->received);
    $this->assertFalse($connection->isFinished());

    $socket->incoming = ['END'];
    $connection->onReadable();

    $this->assertSame('helloEND', $protocol->received);
    $this->assertTrue($connection->isFinished());
  }

  public function testRespondsWithPartialDataWhenClientStopsSending(): void
  {
    $socket = new FakeSocket();
    $socket->incoming = ['hello', ''];
    $protocol = new FakeProtocol();
    $connection = new Connection($socket, $protocol, 1000);

    $connection->onReadable();

    $this->assertSame('hello', $protocol->received);
    $this->assertSame('response', $socket->written);
  }

  public function testFinishesWithoutRespondingWhenClientSendsNothing(): void
  {
    $socket = new FakeSocket();
    $socket->incoming = [''];
    $protocol = new FakeProtocol();
    $connection = new Connection($socket, $protocol, 1000);

    $connection->onReadable();

    $this->assertNull($protocol->received);
    $this->assertSame('', $socket->written);
    $this->assertTrue($connection->isFinished());
  }

  public function testStopsReadingOnceBufferExceedsMaxSize(): void
  {
    $socket = new FakeSocket();
    $chunk = str_repeat('a', 8192);
    $socket->incoming = array_fill(0, (int) (Connection::MAX_BUFFER_SIZE / 8192) * 2, $chunk);
    $protocol = new FakeProtocol();
    $connection = new Connection($socket, $protocol, 1000);

    $connection->onReadable();

    $this->assertGreaterThan(Connection::MAX_BUFFER_SIZE, strlen((string) $protocol->received));
    $this->assertNotEmpty($socket->incoming);
  }

  public function testResumesWritingWhenSocketBecomesWritable(): void
  {
    $socket = new FakeSocket();
    $socket->incoming = ['END'];
    $socket->writableBytes = 3;
    $connection = new Connection($socket, new FakeProtocol(), 1000);

    $connection->onReadable();

    $this->assertSame('res', $socket->written);
    $this->assertFalse($connection->isFinished());

    $socket->writableBytes = null;
    $connection->onWritable();

    $this->assertSame('response', $socket->written);
    $this->assertTrue($connection->isFinished());
  }

  public function testIgnoresWritableBeforeResponding(): void
  {
    $socket = new FakeSocket();
    $connection = new Connection($socket, new FakeProtocol(), 1000);

    $connection->onWritable();

    $this->assertSame('', $socket->written);
    $this->assertFalse($connection->isFinished());
  }

  public function testFinishesOnSocketError(): void
  {
    $socket = new FakeSocket();
    $socket->failOnRead = true;
    $connection = new Connection($socket, new FakeProtocol(), 1000);

    $connection->onReadable();

    $this->assertTrue($connection->isFinished());
  }

  public function testExpiresAtDeadline(): void
  {
    $connection = new Connection(new FakeSocket(), new FakeProtocol(), 1000);

    $this->assertFalse($connection->isExpired(999));
    $this->assertTrue($connection->isExpired(1000));
  }
}
