<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests\Platform;

use Urassh\Phalcon\Server\Platform\StreamSelect\StreamSelectPlatform;
use Urassh\Phalcon\Server\Port\Platform;

final class StreamSelectPlatformTest extends PlatformContractTestCase
{
  protected function createPlatform(): Platform
  {
    return new StreamSelectPlatform();
  }
}
