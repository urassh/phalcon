<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Http;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Http\EchoRequestHandler;
use Urassh\Phalcon\Http\ValueObject\Request;
use Urassh\Phalcon\Http\ValueObject\Response;

final class EchoRequestHandlerTest extends TestCase
{
  public function testEchoesRequestAndReturnsOkResponse(): void
  {
    $this->expectOutputString("POST / HTTP/1.1\nhost: localhost\ncontent-length: 3\n\na=1");

    $request = Request::parse("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\na=1");
    $response = (new EchoRequestHandler())->handle($request);

    $this->assertEquals(new Response("OK\n"), $response);
  }
}
