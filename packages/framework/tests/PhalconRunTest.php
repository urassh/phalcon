<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Runner;
use Urassh\Phalcon\Phalcon;

/**
 * Phalcon::run() が登録したサーバに任せること、登録がなければ SAPI のリクエストを 1 件処理することを確かめる
 */
final class PhalconRunTest extends TestCase
{
  /** @var resource|null */
  private $process = null;

  protected function tearDown(): void
  {
    if ($this->process !== null) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
  }

  public function testRunsOnRegisteredServer(): void
  {
    $server = new class () implements Runner {
      public ?RequestHandlerInterface $handler = null;

      public function run(RequestHandlerInterface $handler): void
      {
        $this->handler = $handler;
      }
    };

    $app = (new Phalcon())->useServer($server);
    $app->run();

    $this->assertSame($app, $server->handler);
  }

  public function testFailsOnCliWithoutServer(): void
  {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('No server is registered');

    (new Phalcon())->run();
  }

  public function testHandlesSapiRequestWithoutServer(): void
  {
    $address = $this->startSapi(__DIR__ . '/Fixtures/run.php');

    $this->assertSame(
      ['200', '{"id":"42"}'],
      $this->send($address, "GET /users/42 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n"),
    );
    $this->assertSame(
      ['201', 'created hello'],
      $this->send(
        $address,
        "POST /memos HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\n"
        . "Content-Length: 11\r\nConnection: close\r\n\r\ntitle=hello",
      ),
    );
  }

  /**
   * php -S でルータスクリプトを動かし、待ち受けたアドレスを返す
   */
  private function startSapi(string $script): string
  {
    // 空いているポートを OS に選ばせてから閉じ、php -S に渡す
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $this->assertIsResource($probe);
    $address = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    $process = proc_open(
      [PHP_BINARY, '-S', $address, $script],
      [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);
    $this->process = $process;

    // 待ち受けを始めるまで接続を試す
    for ($i = 0; $i < 50; $i++) {
      $client = @stream_socket_client("tcp://{$address}", $errno, $errstr, 1);
      if ($client !== false) {
        fclose($client);

        return $address;
      }
      usleep(100_000);
    }
    $this->fail("php -S did not start on {$address}");
  }

  /**
   * @return array{string, string} ステータスコードと本文
   */
  private function send(string $address, string $request): array
  {
    $client = stream_socket_client("tcp://{$address}", $errno, $errstr, 5);
    $this->assertIsResource($client, (string) $errstr);

    fwrite($client, $request);
    $response = (string) stream_get_contents($client);
    fclose($client);

    if (preg_match('#\AHTTP/\d\.\d (\d{3})#', $response, $matches) !== 1) {
      $this->fail("Unexpected response: {$response}");
    }
    $parts = explode("\r\n\r\n", $response, 2);

    return [$matches[1], $parts[1] ?? ''];
  }
}
