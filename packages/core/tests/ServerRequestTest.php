<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Urassh\Phalcon\Core\ServerRequest;
use Urassh\Phalcon\Core\Stream;
use Urassh\Phalcon\Core\UploadedFile;

final class ServerRequestTest extends TestCase
{
  public function testAttributes(): void
  {
    $original = new ServerRequest('GET', '/');
    $request = $original->withAttribute('id', '42')->withAttribute('null', null);

    $this->assertSame([], $original->getAttributes());
    $this->assertSame('42', $request->getAttribute('id'));
    $this->assertNull($request->getAttribute('null', 'default'));
    $this->assertSame('default', $request->getAttribute('missing', 'default'));
    $this->assertSame(['null' => null], $request->withoutAttribute('id')->getAttributes());
  }

  public function testParamsAreImmutable(): void
  {
    $original = new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => '127.0.0.1']);
    $request = $original
      ->withQueryParams(['q' => 'php'])
      ->withCookieParams(['session' => 'abc'])
      ->withParsedBody(['name' => 'x']);

    $this->assertSame([], $original->getQueryParams());
    $this->assertSame([], $original->getCookieParams());
    $this->assertNull($original->getParsedBody());
    $this->assertSame(['q' => 'php'], $request->getQueryParams());
    $this->assertSame(['session' => 'abc'], $request->getCookieParams());
    $this->assertSame(['name' => 'x'], $request->getParsedBody());
    $this->assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
  }

  public function testParsedBodyAcceptsObject(): void
  {
    $body = new stdClass();

    $this->assertSame($body, (new ServerRequest('POST', '/'))->withParsedBody($body)->getParsedBody());
  }

  public function testThrowsOnInvalidParsedBody(): void
  {
    $this->expectException(InvalidArgumentException::class);

    (new ServerRequest('POST', '/'))->withParsedBody('string');
  }

  public function testUploadedFilesMustBeATreeOfUploadedFile(): void
  {
    $file = new UploadedFile(new Stream('data'), 4);
    $request = (new ServerRequest('POST', '/'))->withUploadedFiles(['docs' => [$file]]);

    $this->assertSame(['docs' => [$file]], $request->getUploadedFiles());

    $this->expectException(InvalidArgumentException::class);
    $request->withUploadedFiles(['docs' => ['not a file']]);
  }

  public function testFromGlobalsBuildsRequestFromServerParams(): void
  {
    $server = [
      'REQUEST_METHOD' => 'GET',
      'REQUEST_URI' => '/users/42?sort=asc',
      'QUERY_STRING' => 'sort=asc',
      'SERVER_PROTOCOL' => 'HTTP/2.0',
      'HTTPS' => 'on',
      'HTTP_HOST' => 'example.com',
      'HTTP_ACCEPT_LANGUAGE' => 'ja',
      'CONTENT_TYPE' => '',
      'CONTENT_LENGTH' => '',
      'REMOTE_ADDR' => '127.0.0.1',
    ];

    $request = ServerRequest::fromGlobals($server, ['sort' => 'asc'], [], ['session' => 'abc'], '');

    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('https://example.com/users/42?sort=asc', (string) $request->getUri());
    $this->assertSame('/users/42?sort=asc', $request->getRequestTarget());
    $this->assertSame('2.0', $request->getProtocolVersion());
    // FastCGI が空文字で渡す CONTENT_* はヘッダにしない
    $this->assertSame(['Host' => ['example.com'], 'Accept-Language' => ['ja']], $request->getHeaders());
    $this->assertSame(['sort' => 'asc'], $request->getQueryParams());
    $this->assertSame(['session' => 'abc'], $request->getCookieParams());
    $this->assertNull($request->getParsedBody());
    $this->assertSame($server, $request->getServerParams());
  }

  public function testFromGlobalsUsesServerNameWithoutHostHeader(): void
  {
    $server = ['SERVER_NAME' => 'localhost', 'SERVER_PORT' => '8080', 'QUERY_STRING' => 'q=1'];

    $request = ServerRequest::fromGlobals($server, [], [], [], '');

    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('http://localhost:8080/?q=1', (string) $request->getUri());
    $this->assertSame('1.1', $request->getProtocolVersion());
  }

  public function testFromGlobalsOmitsDefaultPort(): void
  {
    $server = ['SERVER_NAME' => 'localhost', 'SERVER_PORT' => '443', 'HTTPS' => 'on', 'REQUEST_URI' => '/'];

    $this->assertSame('https://localhost/', (string) ServerRequest::fromGlobals($server, [], [], [], '')->getUri());
  }

  public function testFromGlobalsTreatsHttpsOffAsHttp(): void
  {
    $server = ['HTTP_HOST' => 'localhost', 'HTTPS' => 'off', 'REQUEST_URI' => '/'];

    $this->assertSame('http://localhost/', (string) ServerRequest::fromGlobals($server, [], [], [], '')->getUri());
  }

  public function testFromGlobalsSetsParsedBodyForPostForm(): void
  {
    $server = [
      'REQUEST_METHOD' => 'POST',
      'REQUEST_URI' => '/memos',
      'HTTP_HOST' => 'localhost',
      'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8',
      'CONTENT_LENGTH' => '7',
    ];

    $request = ServerRequest::fromGlobals($server, [], ['title' => 'x'], [], 'title=x');

    $this->assertSame(['title' => 'x'], $request->getParsedBody());
    $this->assertSame('title=x', (string) $request->getBody());
    $this->assertSame('7', $request->getHeaderLine('Content-Length'));
  }

  public function testFromGlobalsLeavesParsedBodyEmptyForOtherBodies(): void
  {
    // PHP は POST 以外や JSON では $_POST を埋めないので、空の $_POST を parsedBody にしない
    $json = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/json'];
    $put = ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

    $this->assertNull(ServerRequest::fromGlobals($json, [], [], [], '{"a":1}')->getParsedBody());
    $this->assertNull(ServerRequest::fromGlobals($put, [], [], [], 'a=1')->getParsedBody());
  }
}
