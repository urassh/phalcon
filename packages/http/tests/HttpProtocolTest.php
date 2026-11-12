<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Http\HttpProtocol;

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
      "HTTP/1.1 200 OK\r\nContent-Length: 13\r\nConnection: close\r\n\r\nPOST /a?b=1 x",
      $protocol->respond("POST /a?b=1 HTTP/1.1\r\nContent-Length: 1\r\n\r\nx"),
    );
  }

  public function testRespondReturnsBadRequestOnMalformedRequest(): void
  {
    $protocol = $this->createProtocol();

    $this->assertSame(
      "HTTP/1.1 400 Bad Request\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Length: 12\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . "Bad Request\n",
      $protocol->respond("garbage\r\n\r\n"),
    );
  }

  public function testRespondReturnsInternalServerErrorWhenHandlerThrows(): void
  {
    $protocol = new HttpProtocol(new class () implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        throw new RuntimeException('secret detail');
      }
    });

    $previous = ini_set('error_log', '/dev/null');
    try {
      $response = $protocol->respond("GET / HTTP/1.1\r\n\r\n");
    } finally {
      ini_set('error_log', $previous === false ? '' : $previous);
    }

    $this->assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $response);
    $this->assertStringNotContainsString('secret detail', $response);
  }

  private function createProtocol(): HttpProtocol
  {
    return new HttpProtocol(new class () implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        return new Response(body: "{$request->getMethod()} {$request->getRequestTarget()} {$request->getBody()}");
      }
    });
  }
}
