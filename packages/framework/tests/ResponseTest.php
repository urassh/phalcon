<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use JsonException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Response;

final class ResponseTest extends TestCase
{
  public function testToPsrCopiesStatusHeadersAndBody(): void
  {
    $response = Response::text("hello\n", 404)->withHeader('X-Test', '1')->toPsr(new Psr17Factory());

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Not Found', $response->getReasonPhrase());
    $this->assertSame(
      ['Content-Type' => ['text/plain; charset=utf-8'], 'X-Test' => ['1']],
      $response->getHeaders(),
    );
    $this->assertSame("hello\n", (string) $response->getBody());
  }

  public function testHtmlSetsContentType(): void
  {
    $this->assertSame('text/html; charset=utf-8', Response::html('<p>hi</p>')->header('Content-Type'));
  }

  public function testJsonEncodesDataAndSetsContentType(): void
  {
    $response = Response::json(['id' => 42, 'name' => 'うらっしゅ'], 201);

    $this->assertSame(201, $response->status());
    $this->assertSame('application/json', $response->header('content-type'));
    $this->assertSame('{"id":42,"name":"うらっしゅ"}', $response->body());
  }

  public function testJsonAcceptsJsonSerializable(): void
  {
    $data = new class () implements \JsonSerializable {
      public function jsonSerialize(): mixed
      {
        return ['ok' => true];
      }
    };

    $this->assertSame('{"ok":true}', Response::json($data)->body());
  }

  public function testJsonThrowsOnUnencodableData(): void
  {
    $this->expectException(JsonException::class);

    Response::json(NAN);
  }

  public function testWithMethodsReturnNewInstance(): void
  {
    $original = Response::text('created');
    $modified = $original->withStatus(201)->withHeader('Location', '/users/42');

    $this->assertSame(200, $original->status());
    $this->assertNull($original->header('Location'));
    $this->assertSame(201, $modified->status());
    $this->assertSame('/users/42', $modified->header('location'));
  }

  public function testWithHeaderReplacesSameNameCaseInsensitively(): void
  {
    $response = Response::text('x')->withHeader('content-type', 'text/csv')->toPsr(new Psr17Factory());

    $this->assertSame(['content-type' => ['text/csv']], $response->getHeaders());
  }

  public function testViewIsRenderedWithRenderer(): void
  {
    $response = Response::view('users/show', ['name' => 'urassh'], 201)
      ->withHeader('X-Test', '1')
      ->withStatus(202);

    $this->assertTrue($response->needsRender());

    $rendered = $response->render(new class () implements Renderer {
      public function render(string $name, array $data): string
      {
        return $name . ':' . json_encode($data);
      }
    });

    $this->assertFalse($rendered->needsRender());
    $this->assertSame(202, $rendered->status());
    $this->assertSame('1', $rendered->header('X-Test'));
    $this->assertSame('text/html; charset=utf-8', $rendered->header('Content-Type'));
    $this->assertSame('users/show:{"name":"urassh"}', $rendered->body());
  }

  public function testToPsrThrowsOnUnrenderedView(): void
  {
    $this->expectException(LogicException::class);

    Response::view('index')->toPsr(new Psr17Factory());
  }
}
