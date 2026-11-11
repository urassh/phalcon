<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server\Platform;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Internal\Server\Port\ListeningSocket;
use Urassh\Phalcon\Internal\Server\Port\Platform;
use Urassh\Phalcon\Internal\Server\Port\Poller;
use Urassh\Phalcon\Internal\Server\Port\Readiness;
use Urassh\Phalcon\Internal\Server\Port\Socket;

/**
 * どの Platform の実装も満たすべき約束を、実際のループバック接続で確かめる
 */
abstract class PlatformContractTestCase extends TestCase
{
  private const WAIT_MS = 1000;

  private ?ListeningSocket $listener = null;
  private Poller $poller;
  /** @var list<resource> */
  private array $clients = [];

  abstract protected function createPlatform(): Platform;

  private function listener(): ListeningSocket
  {
    if ($this->listener === null) {
      throw new RuntimeException('Listener is not initialized');
    }

    return $this->listener;
  }

  protected function setUp(): void
  {
    $platform = $this->createPlatform();
    $this->listener = $platform->listen('127.0.0.1', 0);
    $this->poller = $platform->createPoller();
  }

  protected function tearDown(): void
  {
    foreach ($this->clients as $client) {
      if (is_resource($client)) {
        fclose($client);
      }
    }
    $this->listener?->close();
  }

  public function testListensOnAssignedPort(): void
  {
    $this->assertMatchesRegularExpression('/^127\.0\.0\.1:[1-9]\d*$/', $this->listener()->localAddress());
  }

  public function testAcceptReturnsNullWhenNoConnectionIsPending(): void
  {
    $this->assertNull($this->listener()->accept());
  }

  public function testNotifiesListenerWhenClientConnects(): void
  {
    $this->poller->add($this->listener()->fd());
    $this->connect();

    $this->waitUntil($this->listener()->fd(), fn (Readiness $r) => $r->readable);

    $this->assertNotNull($this->listener()->accept());
    $this->assertNull($this->listener()->accept());
  }

  public function testReadsUntilWouldBlock(): void
  {
    [$socket, $client] = $this->acceptWatched();
    fwrite($client, 'hello');

    $this->waitUntil($socket->fd(), fn (Readiness $r) => $r->readable);

    $this->assertSame('hello', $socket->read(8192));
    $this->assertNull($socket->read(8192));
  }

  public function testNotifiesOnlyWhenStateChanges(): void
  {
    [$socket, $client] = $this->acceptWatched();
    fwrite($client, 'first');
    $this->waitUntil($socket->fd(), fn (Readiness $r) => $r->readable);
    $this->drain($socket);
    // 登録直後の書き込み可能の通知が別に来ている場合があるので、ここで読み捨てる
    $this->poller->wait(50);

    $this->assertSame([], $this->readinessOf($socket->fd(), $this->poller->wait(100)));

    fwrite($client, 'second');
    $this->waitUntil($socket->fd(), fn (Readiness $r) => $r->readable);

    $this->assertSame('second', $this->drain($socket));
  }

  public function testReadReturnsEmptyStringWhenClientClosed(): void
  {
    [$socket, $client] = $this->acceptWatched();
    fwrite($client, 'bye');
    fclose($client);

    $this->waitUntil($socket->fd(), fn (Readiness $r) => $r->readable || $r->hangup);

    $this->assertSame('bye', $socket->read(8192));
    $this->assertSame('', $socket->read(8192));
  }

  public function testWritesToClient(): void
  {
    [$socket, $client] = $this->acceptWatched();

    $this->assertSame(8, $socket->write('response'));

    $this->assertSame('response', fread($client, 8));
  }

  public function testNotifiesWritableAfterSendBufferIsDrained(): void
  {
    [$socket, $client] = $this->acceptWatched();
    $chunk = str_repeat('x', 65536);
    $total = 0;
    while (($written = $socket->write($chunk)) > 0) {
      $total += $written;
    }

    $received = 0;
    while ($received < $total) {
      $data = fread($client, 65536);
      if ($data === false || $data === '') {
        $this->fail('Client stopped receiving');
      }
      $received += strlen($data);
    }

    $this->waitUntil($socket->fd(), fn (Readiness $r) => $r->writable);
    $this->assertGreaterThan(0, $socket->write('more'));
  }

  public function testStopsNotifyingAfterRemove(): void
  {
    [$socket, $client] = $this->acceptWatched();
    $this->poller->remove($socket->fd());
    fwrite($client, 'ignored');

    $this->assertSame([], $this->readinessOf($socket->fd(), $this->poller->wait(100)));
  }

  /**
   * @return array{Socket, resource}
   */
  private function acceptWatched(): array
  {
    $client = $this->connect();
    $socket = null;
    for ($i = 0; $i < 100 && $socket === null; $i++) {
      $socket = $this->listener()->accept();
      if ($socket === null) {
        usleep(10_000);
      }
    }
    if ($socket === null) {
      $this->fail('Connection was not accepted');
    }

    $this->poller->add($socket->fd());

    return [$socket, $client];
  }

  /**
   * @return resource
   */
  private function connect(): mixed
  {
    $client = stream_socket_client('tcp://' . $this->listener()->localAddress(), $errno, $errstr, 1);
    if ($client === false) {
      throw new RuntimeException("Failed to connect: {$errstr} ({$errno})");
    }
    stream_set_timeout($client, 1);
    $this->clients[] = $client;

    return $client;
  }

  /**
   * @param callable(Readiness): bool $predicate
   */
  private function waitUntil(int $fd, callable $predicate): void
  {
    $deadline = microtime(true) + self::WAIT_MS / 1000;
    while (microtime(true) < $deadline) {
      foreach ($this->readinessOf($fd, $this->poller->wait(self::WAIT_MS)) as $readiness) {
        if ($predicate($readiness)) {
          $this->addToAssertionCount(1);

          return;
        }
      }
    }

    $this->fail("Expected notification for fd {$fd} did not arrive");
  }

  /**
   * @param list<Readiness> $notifications
   * @return list<Readiness>
   */
  private function readinessOf(int $fd, array $notifications): array
  {
    return array_values(array_filter($notifications, fn (Readiness $r) => $r->fd === $fd));
  }

  private function drain(Socket $socket): string
  {
    $data = '';
    while (($chunk = $socket->read(8192)) !== null && $chunk !== '') {
      $data .= $chunk;
    }

    return $data;
  }
}
