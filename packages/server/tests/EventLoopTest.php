<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Server\EventLoop;
use Urassh\Phalcon\Server\Port\Readiness;

final class EventLoopTest extends TestCase
{
  private FakeListeningSocket $listener;
  private FakePoller $poller;
  private FakeClock $clock;
  private EventLoop $loop;

  protected function setUp(): void
  {
    $this->listener = new FakeListeningSocket();
    $this->poller = new FakePoller();
    $this->clock = new FakeClock();
    $this->loop = new EventLoop($this->listener, $this->poller, new FakeProtocol(), $this->clock);
  }

  public function testWatchesListenerAndWaitsWithoutTimeoutWhenIdle(): void
  {
    $this->loop->runOnce();

    $this->assertSame([FakeListeningSocket::FD], $this->poller->watching);
    $this->assertSame([null], $this->poller->timeouts);
  }

  public function testAcceptsAllPendingConnectionsInOneNotification(): void
  {
    $this->listener->pending = [new FakeSocket(10), new FakeSocket(11), new FakeSocket(12)];
    $this->poller->notifications = [[new Readiness(FakeListeningSocket::FD, readable: true)]];

    $this->loop->runOnce();

    $this->assertSame([FakeListeningSocket::FD, 10, 11, 12], $this->poller->watching);
    $this->assertSame([], $this->listener->pending);
  }

  public function testRespondsAndDropsConnectionWhenReadable(): void
  {
    $socket = $this->accept(10);
    $socket->incoming = ['helloEND'];
    $this->poller->notifications = [[new Readiness(10, readable: true)]];

    $this->loop->runOnce();

    $this->assertSame('response', $socket->written);
    $this->assertTrue($socket->closed);
    $this->assertSame([FakeListeningSocket::FD], $this->poller->watching);
  }

  public function testResumesWritingWhenWritable(): void
  {
    $socket = $this->accept(10);
    $socket->incoming = ['END'];
    $socket->writableBytes = 3;
    $this->poller->notifications = [[new Readiness(10, readable: true)]];
    $this->loop->runOnce();

    $this->assertFalse($socket->closed);

    $socket->writableBytes = null;
    $this->poller->notifications = [[new Readiness(10, writable: true)]];
    $this->loop->runOnce();

    $this->assertSame('response', $socket->written);
    $this->assertTrue($socket->closed);
  }

  public function testDropsConnectionOnHangup(): void
  {
    $socket = $this->accept(10);
    $this->poller->notifications = [[new Readiness(10, hangup: true)]];

    $this->loop->runOnce();

    $this->assertTrue($socket->closed);
    $this->assertSame([FakeListeningSocket::FD], $this->poller->watching);
  }

  public function testWaitsUntilNearestDeadlineAndDropsExpiredConnection(): void
  {
    $socket = $this->accept(10);
    $this->clock->now = 1000;

    $this->loop->runOnce();

    $this->assertSame(EventLoop::TIMEOUT_MS - 1000, $this->poller->timeouts[1]);
    $this->assertFalse($socket->closed);

    $this->clock->now = EventLoop::TIMEOUT_MS;
    $this->loop->runOnce();

    $this->assertTrue($socket->closed);
    $this->assertSame([FakeListeningSocket::FD], $this->poller->watching);
  }

  private function accept(int $fd): FakeSocket
  {
    $socket = new FakeSocket($fd);
    $this->listener->pending = [$socket];
    $this->poller->notifications = [[new Readiness(FakeListeningSocket::FD, readable: true)]];
    $this->loop->runOnce();

    return $socket;
  }
}
