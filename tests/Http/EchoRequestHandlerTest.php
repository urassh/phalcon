<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Http;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Http\EchoRequestHandler;
use Urassh\Phalcon\Http\Response;

final class EchoRequestHandlerTest extends TestCase
{
  public function testEchoesRequestAndReturnsOkResponse(): void
  {
    $this->expectOutputString("GET / HTTP/1.1\r\n\r\n");

    $response = (new EchoRequestHandler())->handle("GET / HTTP/1.1\r\n\r\n");

    $this->assertEquals(new Response("OK\n"), $response);
  }
}
