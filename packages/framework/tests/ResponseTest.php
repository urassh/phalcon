<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use JsonException;
use LogicException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Core\ServerRequest;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Request;
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

  public function testRedirectSetsLocationWithEmptyBody(): void
  {
    $response = Response::redirect('/users/42');

    $this->assertSame(302, $response->status());
    $this->assertSame('/users/42', $response->header('Location'));
    $this->assertNull($response->header('Content-Type'));
    $this->assertSame('', $response->body());
  }

  public function testRedirectAcceptsStatus(): void
  {
    $this->assertSame(303, Response::redirect('/users', 303)->status());
  }

  public function testSeeOtherRedirectsWith303(): void
  {
    $response = Response::seeOther('/memos');

    $this->assertSame(303, $response->status());
    $this->assertSame('/memos', $response->header('Location'));
  }

  public function testBackRedirectsToSameOriginReferer(): void
  {
    $response = Response::back($this->requestFrom('http://localhost:8080/memos/1/edit?tab=a'));

    $this->assertSame(302, $response->status());
    $this->assertSame('/memos/1/edit?tab=a', $response->header('Location'));
  }

  public function testBackAcceptsRelativeRefererAndStatus(): void
  {
    $response = Response::back($this->requestFrom('/memos'), status: 303);

    $this->assertSame(303, $response->status());
    $this->assertSame('/memos', $response->header('Location'));
  }

  #[DataProvider('unsafeReferers')]
  public function testBackFallsBackOnMissingOrUnsafeReferer(?string $referer): void
  {
    $response = Response::back($this->requestFrom($referer), '/memos');

    $this->assertSame('/memos', $response->header('Location'));
  }

  /**
   * @return array<string, array{?string}>
   */
  public static function unsafeReferers(): array
  {
    return [
      'missing' => [null],
      'other host' => ['http://evil.com/memos'],
      'other port' => ['http://localhost:9999/memos'],
      'protocol relative' => ['//evil.com/memos'],
      'double slash path' => ['http://localhost:8080//evil.com'],
      'backslash' => ['/\\evil.com'],
      'javascript scheme' => ['javascript:alert(1)'],
      'malformed' => ['http:///'],
    ];
  }

  public function testCreatedSetsLocationAndJsonBody(): void
  {
    $response = Response::created('/users/42', ['id' => 42]);

    $this->assertSame(201, $response->status());
    $this->assertSame('/users/42', $response->header('Location'));
    $this->assertSame('application/json', $response->header('Content-Type'));
    $this->assertSame('{"id":42}', $response->body());
  }

  public function testCreatedWithoutDataHasEmptyBodyWithoutContentType(): void
  {
    $response = Response::created('/users/42');

    $this->assertSame(201, $response->status());
    $this->assertSame('/users/42', $response->header('Location'));
    $this->assertNull($response->header('Content-Type'));
    $this->assertSame('', $response->body());
  }

  public function testCreatedRejectsHeaderInjection(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Response::created("/users/42\r\nSet-Cookie: a=b");
  }

  public function testNoContentHasEmptyBodyWithoutContentType(): void
  {
    $response = Response::noContent();

    $this->assertSame(204, $response->status());
    $this->assertNull($response->header('Content-Type'));
    $this->assertSame('', $response->body());
  }

  public function testWithHeaderRejectsHeaderInjection(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Response::redirect("/memos\r\nSet-Cookie: a=b");
  }

  public function testWithHeaderRejectsInvalidName(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Response::text('')->withHeader("X-Test\r\n", 'a');
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

      public function exists(string $name): bool
      {
        return true;
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

  private function requestFrom(?string $referer): Request
  {
    $headers = ['Host' => 'localhost:8080'];
    if ($referer !== null) {
      $headers['Referer'] = $referer;
    }

    return new Request(new ServerRequest('POST', '/memos', $headers));
  }
}
