<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * php -S (組み込みサーバの SAPI) の上で ServerRequest::fromGlobals() と SapiEmitter を動かし、実際の応答を確かめる
 */
final class SapiTest extends TestCase
{
  /** @var resource|null */
  private $process = null;

  private string $address = '';

  protected function setUp(): void
  {
    // 空いているポートを OS に選ばせてから閉じ、php -S に渡す
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $this->assertIsResource($probe);
    $this->address = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    $process = proc_open(
      [PHP_BINARY, '-S', $this->address, __DIR__ . '/Fixtures/sapi.php'],
      [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);
    $this->process = $process;

    // 待ち受けを始めるまで接続を試す
    for ($i = 0; $i < 50; $i++) {
      $client = @stream_socket_client("tcp://{$this->address}", $errno, $errstr, 1);
      if ($client !== false) {
        fclose($client);

        return;
      }
      usleep(100_000);
    }
    $this->fail("php -S did not start on {$this->address}");
  }

  protected function tearDown(): void
  {
    if ($this->process !== null) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
  }

  public function testEmitsStatusHeadersAndBody(): void
  {
    [$head, $body] = $this->send("GET /users/42?sort=asc HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");

    $this->assertStringStartsWith("HTTP/1.1 201 Created Here\r\n", $head);
    $this->assertStringContainsString("\r\nContent-Type: application/json\r\n", $head);
    $this->assertStringContainsString("\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n", $head);
    $this->assertSame([
      'method' => 'GET',
      'uri' => 'http://example.com/users/42?sort=asc',
      'host' => 'example.com',
      'query' => ['sort' => 'asc'],
      'parsedBody' => null,
      'body' => '',
    ], json_decode($body, true));
  }

  public function testReadsPostedForm(): void
  {
    [, $body] = $this->send(
      "POST /memos HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\n"
      . "Content-Length: 11\r\nConnection: close\r\n\r\ntitle=hello",
    );

    $decoded = json_decode($body, true);
    $this->assertIsArray($decoded);
    $this->assertSame('POST', $decoded['method']);
    $this->assertSame(['title' => 'hello'], $decoded['parsedBody']);
    $this->assertSame('title=hello', $decoded['body']);
  }

  /**
   * @return array{string, string} ヘッダ部 (各行が \r\n で終わる) と本文
   */
  private function send(string $request): array
  {
    $client = stream_socket_client("tcp://{$this->address}", $errno, $errstr, 5);
    $this->assertIsResource($client, (string) $errstr);

    fwrite($client, $request);
    $response = (string) stream_get_contents($client);
    fclose($client);

    $parts = explode("\r\n\r\n", $response, 2);
    $this->assertCount(2, $parts, $response);

    return [$parts[0] . "\r\n", $parts[1]];
  }
}
