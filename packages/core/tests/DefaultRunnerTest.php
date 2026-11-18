<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Core\Runner;

final class DefaultRunnerTest extends TestCase
{
  protected function tearDown(): void
  {
    DefaultRunner::register(null);
  }

  public function testReturnsNullWithoutRegistration(): void
  {
    DefaultRunner::register(null);

    $this->assertNull(DefaultRunner::create());
  }

  public function testCreatesRunnerFromLatestFactory(): void
  {
    $calls = 0;
    DefaultRunner::register(function () use (&$calls): Runner {
      $calls++;

      return self::runner();
    });
    $runner = self::runner();
    DefaultRunner::register(fn (): Runner => $runner);

    $this->assertSame($runner, DefaultRunner::create());
    // 上書きされた関数は呼ばない
    $this->assertSame(0, $calls);
  }

  public function testPassesOptionsToFactory(): void
  {
    $received = null;
    DefaultRunner::register(function (array $options) use (&$received): Runner {
      $received = $options;

      return self::runner();
    });

    DefaultRunner::create(['host' => '127.0.0.1', 'port' => 9000]);
    $this->assertSame(['host' => '127.0.0.1', 'port' => 9000], $received);

    DefaultRunner::create();
    $this->assertSame([], $received);
  }

  private static function runner(): Runner
  {
    return new class () implements Runner {
      public function run(RequestHandlerInterface $handler): void
      {
      }
    };
  }
}
