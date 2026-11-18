<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Internal\Middleware\FormBody;

final class FormBodyTest extends TestCase
{
  /**
   * @return iterable<string, array{string, string}>
   */
  public static function formRequests(): iterable
  {
    yield 'POST' => ['POST', 'application/x-www-form-urlencoded'];
    yield 'PATCH' => ['PATCH', 'application/x-www-form-urlencoded'];
    yield 'with charset' => ['POST', 'application/x-www-form-urlencoded; charset=UTF-8'];
    yield 'uppercase' => ['POST', 'Application/X-WWW-Form-Urlencoded'];
  }

  #[DataProvider('formRequests')]
  public function testParsesUrlencodedBody(string $method, string $contentType): void
  {
    $request = $this->process($method, $contentType, 'title=a+b&body=%E3%81%82&tags[]=x&tags[]=y');

    $this->assertSame(['title' => 'a b', 'body' => 'あ', 'tags' => ['x', 'y']], $request->getParsedBody());
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function otherContentTypes(): iterable
  {
    yield 'none' => [''];
    yield 'json' => ['application/json'];
    yield 'multipart' => ['multipart/form-data; boundary=x'];
    yield 'prefix only' => ['application/x-www-form-urlencoded-extra'];
  }

  #[DataProvider('otherContentTypes')]
  public function testLeavesOtherBodiesUnparsed(string $contentType): void
  {
    $this->assertNull($this->process('POST', $contentType, 'title=a')->getParsedBody());
  }

  public function testKeepsExistingParsedBody(): void
  {
    $request = $this->process('POST', 'application/x-www-form-urlencoded', 'title=a', ['title' => 'given']);

    $this->assertSame(['title' => 'given'], $request->getParsedBody());
  }

  /**
   * ミドルウェアを通したあと、ハンドラが受け取ったリクエストを返す
   *
   * @param array<string, string>|null $parsedBody
   */
  private function process(string $method, string $contentType, string $body, ?array $parsedBody = null): ServerRequestInterface
  {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, '/')
      ->withBody($factory->createStream($body))
      ->withParsedBody($parsedBody);
    if ($contentType !== '') {
      $request = $request->withHeader('Content-Type', $contentType);
    }

    $handler = new class () implements RequestHandlerInterface {
      public ?ServerRequestInterface $received = null;

      public function handle(ServerRequestInterface $request): ResponseInterface
      {
        $this->received = $request;

        return (new Psr17Factory())->createResponse(200);
      }
    };

    (new FormBody())->process($request, $handler);
    $this->assertNotNull($handler->received);

    return $handler->received;
  }
}
