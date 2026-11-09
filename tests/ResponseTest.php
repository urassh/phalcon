<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Response;

final class ResponseTest extends TestCase
{
  public function testToStringBuildsHttpResponseWithContentLength(): void
  {
    $response = Response::text("hello\n");

    $this->assertSame(
      "HTTP/1.1 200 OK\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Length: 6\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . "hello\n",
      $response->toString(),
    );
  }

  public function testToStringUsesGivenStatus(): void
  {
    $response = Response::text("Bad Request\n", 400);

    $this->assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $response->toString());
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
    $response = Response::text('x')->withHeader('content-type', 'text/csv');

    $this->assertSame(
      "HTTP/1.1 200 OK\r\ncontent-type: text/csv\r\nContent-Length: 1\r\nConnection: close\r\n\r\nx",
      $response->toString(),
    );
  }

  public function testManagedHeadersCannotBeOverridden(): void
  {
    $response = Response::text('abc')
      ->withHeader('Content-Length', '999')
      ->withHeader('Connection', 'keep-alive');

    $this->assertSame(
      "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: 3\r\nConnection: close\r\n\r\nabc",
      $response->toString(),
    );
  }
}
