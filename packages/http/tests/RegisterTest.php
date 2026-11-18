<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Http\HttpServer;

/**
 * Composer の autoload.files (src/register.php) で HttpServer が DefaultRunner に登録されていることを確かめる
 */
final class RegisterTest extends TestCase
{
  public function testRegistersHttpServerAsDefaultRunner(): void
  {
    $this->assertInstanceOf(HttpServer::class, DefaultRunner::create());
  }
}
