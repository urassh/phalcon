<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests\Platform;

use Urassh\Phalcon\Server\Platform\Linux\Libc;
use Urassh\Phalcon\Server\Platform\Linux\LinuxPlatform;
use Urassh\Phalcon\Server\Port\ListeningSocket;
use Urassh\Phalcon\Server\Port\Platform;

final class LinuxPlatformTest extends PlatformContractTestCase
{
  protected function setUp(): void
  {
    if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('ffi')) {
      $this->markTestSkipped('Requires Linux with the FFI extension');
    }

    parent::setUp();
  }

  protected function createPlatform(): Platform
  {
    return new LinuxPlatform(Libc::load());
  }

  /**
   * SO_REUSEPORT の listener が複数あると、カーネルが接続をそれぞれに振り分ける。
   * 振り分けはハッシュなので、全部が片方に寄る確率 (2^-31) は無視する
   */
  public function testKernelDistributesConnectionsAcrossReusePortListeners(): void
  {
    $first = $this->listen('127.0.0.1', 0, reusePort: true);
    $second = $this->listen('127.0.0.1', $this->portOf($first), reusePort: true);

    $total = 32;
    for ($i = 0; $i < $total; $i++) {
      $this->connectTo($first->localAddress());
    }

    $accepted = [0, 0];
    $deadline = microtime(true) + 1;
    while (array_sum($accepted) < $total && microtime(true) < $deadline) {
      $accepted[0] += $this->acceptAll($first);
      $accepted[1] += $this->acceptAll($second);
      usleep(10_000);
    }

    $this->assertSame($total, array_sum($accepted));
    $this->assertGreaterThan(0, $accepted[0]);
    $this->assertGreaterThan(0, $accepted[1]);
  }

  private function acceptAll(ListeningSocket $listener): int
  {
    $count = 0;
    while (($socket = $listener->accept()) !== null) {
      $socket->close();
      $count++;
    }

    return $count;
  }
}
