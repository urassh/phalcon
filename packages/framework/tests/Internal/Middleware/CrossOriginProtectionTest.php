<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Exception\ForbiddenException;
use Urassh\Phalcon\Internal\Middleware\CrossOriginProtection;

final class CrossOriginProtectionTest extends TestCase
{
  /**
   * @return iterable<string, array{string, array<string, string>}>
   */
  public static function allowedRequests(): iterable
  {
    yield 'GET from another site' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example']];
    yield 'HEAD from another site' => ['HEAD', ['Sec-Fetch-Site' => 'cross-site']];
    yield 'OPTIONS from another site' => ['OPTIONS', ['Sec-Fetch-Site' => 'cross-site']];
    yield 'same-origin' => ['POST', ['Sec-Fetch-Site' => 'same-origin']];
    yield 'user-initiated' => ['POST', ['Sec-Fetch-Site' => 'none']];
    // Sec-Fetch-Site があればそちらを優先し、Origin は見ない
    yield 'same-origin with mismatched Origin' => ['POST', ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://other.example']];
    yield 'Origin matches Host' => ['POST', ['Origin' => 'http://localhost:8080', 'Host' => 'localhost:8080']];
    yield 'Origin matches Host ignoring case' => ['DELETE', ['Origin' => 'http://LocalHost:8080', 'Host' => 'localhost:8080']];
    yield 'Origin matches IPv6 Host' => ['POST', ['Origin' => 'http://[::1]:8080', 'Host' => '[::1]:8080']];
    yield 'no browser headers' => ['POST', []];
    yield 'trusted origin' => ['POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://app.example.com']];
    yield 'trusted origin ignoring case' => ['PUT', ['Origin' => 'https://APP.example.com', 'Host' => 'api.example.com']];
  }

  /**
   * @param array<string, string> $headers
   */
  #[DataProvider('allowedRequests')]
  public function testPassesRequest(string $method, array $headers): void
  {
    $response = $this->process($method, $headers);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * @return iterable<string, array{string, array<string, string>}>
   */
  public static function blockedRequests(): iterable
  {
    yield 'cross-site POST' => ['POST', ['Sec-Fetch-Site' => 'cross-site']];
    yield 'cross-site PATCH' => ['PATCH', ['Sec-Fetch-Site' => 'cross-site']];
    yield 'cross-site DELETE' => ['DELETE', ['Sec-Fetch-Site' => 'cross-site']];
    yield 'same-site' => ['POST', ['Sec-Fetch-Site' => 'same-site']];
    // Sec-Fetch-Site があれば Origin が Host と一致していても見ない
    yield 'cross-site with matching Origin' => ['POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'http://localhost:8080', 'Host' => 'localhost:8080']];
    yield 'Origin differs from Host' => ['POST', ['Origin' => 'https://evil.example', 'Host' => 'localhost:8080']];
    yield 'Origin differs in port' => ['POST', ['Origin' => 'http://localhost:3000', 'Host' => 'localhost:8080']];
    yield 'Origin without Host' => ['POST', ['Origin' => 'http://localhost:8080']];
    yield 'opaque Origin' => ['POST', ['Origin' => 'null', 'Host' => 'localhost:8080']];
    yield 'unknown method' => ['PURGE', ['Sec-Fetch-Site' => 'cross-site']];
  }

  /**
   * @param array<string, string> $headers
   */
  #[DataProvider('blockedRequests')]
  public function testBlocksRequest(string $method, array $headers): void
  {
    $this->expectException(ForbiddenException::class);

    $this->process($method, $headers);
  }

  /**
   * @param array<string, string> $headers
   */
  private function process(string $method, array $headers): ResponseInterface
  {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, '/');
    foreach ($headers as $name => $value) {
      $request = $request->withHeader($name, $value);
    }

    $handler = new class () implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        return (new Psr17Factory())->createResponse(200);
      }
    };

    return (new CrossOriginProtection(['https://app.example.com']))->process($request, $handler);
  }
}
