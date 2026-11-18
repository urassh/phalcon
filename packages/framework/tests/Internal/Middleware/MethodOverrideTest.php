<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Internal\Middleware\MethodOverride;

final class MethodOverrideTest extends TestCase
{
  /**
   * @return iterable<string, array{string, string}>
   */
  public static function overrides(): iterable
  {
    yield 'PUT' => ['PUT', 'PUT'];
    yield 'PATCH' => ['PATCH', 'PATCH'];
    yield 'DELETE' => ['DELETE', 'DELETE'];
    yield 'lowercase' => ['delete', 'DELETE'];
  }

  #[DataProvider('overrides')]
  public function testOverridesPostWithFormField(string $field, string $expected): void
  {
    $this->assertSame($expected, $this->process('POST', ['_method' => $field]));
  }

  /**
   * @return iterable<string, array{string, array<string, mixed>|null}>
   */
  public static function ignored(): iterable
  {
    // GET に変えると CSRF の判定を抜けたうえで安全なメソッドとして扱われてしまう
    yield 'to GET' => ['POST', ['_method' => 'GET']];
    yield 'to HEAD' => ['POST', ['_method' => 'HEAD']];
    yield 'unknown method' => ['POST', ['_method' => 'PURGE']];
    yield 'array value' => ['POST', ['_method' => ['DELETE']]];
    yield 'without field' => ['POST', ['title' => 'a']];
    yield 'not a form' => ['POST', null];
    yield 'from GET' => ['GET', ['_method' => 'DELETE']];
    yield 'from PUT' => ['PUT', ['_method' => 'DELETE']];
  }

  /**
   * @param array<string, mixed>|null $form
   */
  #[DataProvider('ignored')]
  public function testKeepsMethod(string $method, ?array $form): void
  {
    $this->assertSame($method, $this->process($method, $form));
  }

  /**
   * ミドルウェアを通したあと、ハンドラが受け取ったリクエストのメソッドを返す
   *
   * @param array<string, mixed>|null $form
   */
  private function process(string $method, ?array $form): string
  {
    $request = (new Psr17Factory())->createServerRequest($method, '/')->withParsedBody($form);

    $handler = new class () implements RequestHandlerInterface {
      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        return (new Psr17Factory())->createResponse(200)->withHeader('X-Method', $request->getMethod());
      }
    };

    return (new MethodOverride())->process($request, $handler)->getHeaderLine('X-Method');
  }
}
