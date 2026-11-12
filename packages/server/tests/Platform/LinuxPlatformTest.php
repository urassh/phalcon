<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests\Platform;

use Urassh\Phalcon\Server\Platform\Linux\Libc;
use Urassh\Phalcon\Server\Platform\Linux\LinuxPlatform;
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
}
