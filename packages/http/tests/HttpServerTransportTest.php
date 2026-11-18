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
use Urassh\Phalcon\Http\HttpServer;
use Urassh\Phalcon\Http\Transport;

/**
 * HttpServer がサーバ層 (Transport) を差し替えられること、既定のサーバ層がなければ起動前に失敗することを確かめる
 */
final class HttpServerTransportTest extends TestCase
{
  public function testServesThroughGivenTransport(): void
  {
    $transport = new class () implements Transport {
      public ?HttpProtocol $protocol = null;

      public function serve(HttpProtocol $protocol): never
      {
        $this->protocol = $protocol;

        // serve() は戻らないので、例外で抜けてテストに戻る
        throw new RuntimeException('stopped');
      }
    };

    $handler = new class () implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        return new Response(200, [], "{$request->getMethod()} {$request->getUri()->getPath()}");
      }
    };

    try {
      (new HttpServer($transport))->serve($handler);
    } catch (RuntimeException $e) {
      $this->assertSame('stopped', $e->getMessage());
    }

    $this->assertNotNull($transport->protocol);
    $this->assertSame(
      "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nGET /hi",
      $transport->protocol->respond("GET /hi HTTP/1.1\r\nHost: localhost\r\n\r\n"),
    );
  }

  public function testFailsWithoutServerLayer(): void
  {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/Fixtures/without-server.php'],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);

    $this->assertSame(
      "HttpProtocol loaded\n"
      . "No server layer is configured: install urassh/phalcon-server or pass a Transport to HttpServer\n",
      $stdout,
      (string) $stderr,
    );
  }
}
