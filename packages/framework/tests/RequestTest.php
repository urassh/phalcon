<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Core\ServerRequest;
use Urassh\Phalcon\Internal\Http\BadRequestException;
use Urassh\Phalcon\Request;

final class RequestTest extends TestCase
{
  public function testExposesRequestLineAndBody(): void
  {
    $request = new Request(new ServerRequest('POST', '/users?x=1', ['Host' => 'localhost'], 'a=1', '1.0'));

    $this->assertSame('POST', $request->method());
    $this->assertSame('/users?x=1', $request->target());
    $this->assertSame('HTTP/1.0', $request->version());
    $this->assertSame('a=1', $request->body());
  }

  public function testPathStripsQueryString(): void
  {
    $this->assertSame('/users/42', $this->request('GET', '/users/42?sort=asc')->path());
  }

  public function testPathOfAbsoluteFormTarget(): void
  {
    $this->assertSame('/users', $this->request('GET', 'http://localhost:8080/users?x=1')->path());
    $this->assertSame('/', $this->request('GET', 'http://localhost:8080')->path());
  }

  public function testQueryReturnsDecodedValueOrNull(): void
  {
    $request = $this->request('GET', '/search?q=hello%20world&tags[]=a');

    $this->assertSame('hello world', $request->query('q'));
    $this->assertNull($request->query('missing'));
    $this->assertNull($request->query('tags'));
  }

  public function testHeaderIsCaseInsensitiveAndReturnsFirstValue(): void
  {
    $request = new Request(new ServerRequest('GET', '/', ['X-Token' => ['abc', 'def']]));

    $this->assertSame('abc', $request->header('x-token'));
    $this->assertNull($request->header('X-Missing'));
  }

  public function testParamComesFromAttributes(): void
  {
    $request = $this->request('GET', '/users/42');

    $this->assertNull($request->param('id'));
    $withParams = $request->withParams(['id' => '42']);
    $this->assertSame('42', $withParams->param('id'));
    $this->assertSame('42', $withParams->psr()->getAttribute('id'));
  }

  public function testParamIgnoresNonStringAttributes(): void
  {
    $request = new Request((new ServerRequest('GET', '/'))->withAttribute('user', new \stdClass()));

    $this->assertNull($request->param('user'));
  }

  public function testJsonDecodesObjectsAsArrays(): void
  {
    $request = new Request(new ServerRequest('POST', '/', body: '{"name":"abc"}'));

    $this->assertSame(['name' => 'abc'], $request->json());
  }

  public function testJsonThrowsBadRequestOnMalformedBody(): void
  {
    $this->expectException(BadRequestException::class);

    (new Request(new ServerRequest('POST', '/', body: '{')))->json();
  }

  private function request(string $method, string $target): Request
  {
    return new Request((new Psr17Factory())->createServerRequest($method, $target));
  }
}
