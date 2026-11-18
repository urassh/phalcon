<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\Tests;

use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Http\HttpServer;

/**
 * Composer の autoload.files (src/register.php) で HttpServer が DefaultRunner に登録されていることを確かめる
 */
final class RegisterTest extends TestCase
{
  public function testRegistersHttpServerAsDefaultRunner(): void
  {
    $this->assertInstanceOf(HttpServer::class, DefaultRunner::create());
  }

  public function testListensWithGivenOptions(): void
  {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/Fixtures/registered.php'],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);

    try {
      $line = fgets($pipes[1]);
      if ($line === false) {
        $this->fail('Server did not start: ' . stream_get_contents($pipes[2]));
      }
      // 既定値 (0.0.0.0:8080、ワーカ 1) ではなく、渡した host / port / workers で待ち受ける
      if (preg_match('/\AListening on (127\.0\.0\.1:\d+) \(2 workers\)\z/', trim($line), $matches) !== 1) {
        $this->fail("Unexpected output: {$line}");
      }
      // 全ワーカが listen するまで待つ (それまでは接続拒否になりうる)
      for ($i = 0; $i < 2; $i++) {
        $started = fgets($pipes[1]);
        $this->assertIsString($started);
        $this->assertMatchesRegularExpression('/\Aworker #\d+ \(pid \d+\) started$/', trim($started));
      }

      $client = stream_socket_client("tcp://{$matches[1]}", $errno, $errstr, 5);
      $this->assertIsResource($client, (string) $errstr);
      fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
      $this->assertStringEndsWith("\r\n\r\nregistered", (string) stream_get_contents($client));
      fclose($client);
    } finally {
      proc_terminate($process);
      proc_close($process);
    }
  }
}
