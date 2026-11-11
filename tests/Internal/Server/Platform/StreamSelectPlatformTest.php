<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server\Platform;

use Urassh\Phalcon\Internal\Server\Platform\StreamSelect\StreamSelectPlatform;
use Urassh\Phalcon\Internal\Server\Port\Platform;

final class StreamSelectPlatformTest extends PlatformContractTestCase
{
  protected function createPlatform(): Platform
  {
    return new StreamSelectPlatform();
  }
}
