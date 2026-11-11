<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Http;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Internal\Http\HttpProtocol;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Internal\Http\RequestHandler;
use Urassh\Phalcon\Response;

final class HttpProtocolTest extends TestCase
{
  public function testIsIncompleteUntilHeaderTerminatorArrives(): void
  {
    $protocol = $this->createProtocol();

    $this->assertFalse($protocol->isComplete("GET / HTTP/1.1\r\nHost: localhost\r\n"));
    $this->assertFalse($protocol->isComplete("GET / HTTP/1.1\r\nHost: localhost\r\n\r"));
    $this->assertTrue($protocol->isComplete("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
  }

  public function testWaitsForBodyOfContentLength(): void
  {
    $protocol = $this->createProtocol();

    $this->assertFalse($protocol->isComplete("POST / HTTP/1.1\r\nContent-Length: 3\r\n\r\na="));
    $this->assertTrue($protocol->isComplete("POST / HTTP/1.1\r\nContent-Length: 3\r\n\r\na=1"));
  }

  public function testContentLengthHeaderIsCaseInsensitive(): void
  {
    $protocol = $this->createProtocol();

    $this->assertFalse($protocol->isComplete("POST / HTTP/1.1\r\ncontent-length: 3\r\nHost: localhost\r\n\r\n"));
  }

  public function testContentLengthInBodyIsIgnored(): void
  {
    $protocol = $this->createProtocol();

    $this->assertTrue($protocol->isComplete("POST / HTTP/1.1\r\nContent-Length: 18\r\n\r\nContent-Length: 99"));
  }

  public function testAbsoluteFormTargetIsNotTreatedAsHeader(): void
  {
    $protocol = $this->createProtocol();

    $this->assertTrue($protocol->isComplete("GET http://localhost:8080/ HTTP/1.1\r\n\r\n"));
  }

  public function testRespondPassesParsedRequestToHandler(): void
  {
    $protocol = $this->createProtocol();

    $this->assertSame(
      Response::text('GET /')->toString(),
      $protocol->respond("GET / HTTP/1.1\r\n\r\n"),
    );
  }

  public function testRespondReturnsBadRequestOnMalformedRequest(): void
  {
    $protocol = $this->createProtocol();

    $this->assertSame(
      Response::text("Bad Request\n", 400)->toString(),
      $protocol->respond("garbage\r\n\r\n"),
    );
  }

  private function createProtocol(): HttpProtocol
  {
    return new HttpProtocol(new class implements RequestHandler {
      public function handle(Request $request): Response
      {
        return Response::text("{$request->method()} {$request->target()}");
      }
    });
  }
}
