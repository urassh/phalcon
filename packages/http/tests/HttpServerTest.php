<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;

/**
 * フレームワーク以外の PSR-15 ハンドラを HttpServer で起動し、実際にソケット越しに応答を確かめる
 */
final class HttpServerTest extends TestCase
{
  /** @var resource|null */
  private $process = null;

  private string $address = '';

  protected function setUp(): void
  {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/Fixtures/server.php'],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);
    $this->process = $process;

    // ポート 0 で待ち受けさせ、割り当てられたアドレスを起動時の出力から読む
    $line = fgets($pipes[1]);
    if ($line === false) {
      // 起動に失敗して終了した場合だけ stderr を読む (動いている間に読むと EOF まで戻らない)
      $this->fail('Server did not start: ' . stream_get_contents($pipes[2]));
    }
    if (preg_match('/Listening on (\S+)/', $line, $matches) !== 1) {
      $this->fail("Unexpected output: {$line}");
    }
    $this->address = $matches[1];
  }

  protected function tearDown(): void
  {
    if ($this->process !== null) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
  }

  public function testServesPlainPsr15Handler(): void
  {
    $client = stream_socket_client("tcp://{$this->address}", $errno, $errstr, 5);
    $this->assertIsResource($client, (string) $errstr);

    fwrite($client, "GET /hello?x=1 HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $response = stream_get_contents($client);
    fclose($client);

    $this->assertSame(
      "HTTP/1.1 200 OK\r\nX-Handler: plain-psr15\r\nContent-Length: 10\r\nConnection: close\r\n\r\nGET /hello",
      $response,
    );
  }
}
