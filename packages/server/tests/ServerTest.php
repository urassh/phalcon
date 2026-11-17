<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * workers >= 2 のサーバを別プロセスで起動し、実際のソケット越しに並行性とワーカの監視を確かめる
 */
final class ServerTest extends TestCase
{
  /** @var resource|null */
  private $process = null;
  /** @var resource|null */
  private $stdout = null;
  private string $address = '';
  /** @var list<int> */
  private array $workerPids = [];

  protected function setUp(): void
  {
    if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
      $this->markTestSkipped('Requires the pcntl and posix extensions');
    }
  }

  protected function tearDown(): void
  {
    if ($this->process !== null) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
    // テストが途中で落ちてもワーカを残さない (残ると SO_REUSEPORT で後のテストの接続を奪う)
    foreach ($this->workerPids as $pid) {
      @posix_kill($pid, SIGKILL);
    }
  }

  public function testServesOtherConnectionsWhileWorkerBlocks(): void
  {
    $this->start(4);

    $slow = $this->send('slow');
    usleep(200_000);

    // ハッシュで振り分けるので、ブロック中のワーカに当たる接続もある。
    // 8 本すべてが当たる確率 (4^-8) は無視して、少なくとも 1 本は速く返ることを確かめる
    $fast = [];
    for ($i = 0; $i < 8; $i++) {
      $fast[] = $this->send('fast');
    }
    $responses = $this->readAll($fast, 1.0);

    $this->assertGreaterThanOrEqual(1, count($responses));
    foreach ($responses as $response) {
      $this->assertStringStartsWith('fast ', $response);
    }
    $this->assertStringStartsWith('slow ', $this->readLine($slow, 3.0));
  }

  public function testPrintsResolvedPortWhenWorkersListenOnPortZero(): void
  {
    $this->start(2);

    $this->assertMatchesRegularExpression('/^127\.0\.0\.1:[1-9]\d*$/', $this->address);
    $this->assertStringStartsWith('fast ', $this->readLine($this->send('fast'), 1.0));
  }

  public function testRespawnsWorker(): void
  {
    $this->start(2);

    $dying = $this->send('die');
    $this->assertSame('', $this->readLine($dying, 1.0));

    $respawned = $this->readWorkerStarted(5.0);
    $this->assertNotContains($respawned, array_slice($this->workerPids, 0, 2));
    $this->assertStringStartsWith('fast ', $this->readLine($this->send('fast'), 1.0));
  }

  public function testTerminatesWorkersOnSigterm(): void
  {
    $this->start(2);
    $process = $this->process;
    $this->assertNotNull($process);

    proc_terminate($process);
    // master はワーカを回収してから終了するので、proc_close が戻った時点でワーカも残っていない
    proc_close($process);
    $this->process = null;

    foreach ($this->workerPids as $pid) {
      $this->assertFalse(posix_kill($pid, 0), "worker {$pid} is still alive");
    }
  }

  private function start(int $workers): void
  {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/Fixtures/server.php', (string) $workers],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
    );
    $this->assertIsResource($process);
    $this->process = $process;
    $this->stdout = $pipes[1];

    $line = fgets($this->stdout);
    if ($line === false) {
      $this->fail('Server did not start: ' . stream_get_contents($pipes[2]));
    }
    if (preg_match('/^Listening on (\S+) \((\d+) workers\)$/', $line, $matches) !== 1) {
      $this->fail("Unexpected output: {$line}");
    }
    $this->assertSame((string) $workers, $matches[2]);
    $this->address = $matches[1];

    // 全ワーカが listen するまで待つ (それまでは接続拒否になりうる)
    for ($i = 0; $i < $workers; $i++) {
      $this->readWorkerStarted(5.0);
    }
  }

  /**
   * "worker #n (pid X) started" の行が出るまで stdout を読み、その pid を返す
   */
  private function readWorkerStarted(float $timeout): int
  {
    $stdout = $this->stdout;
    $this->assertNotNull($stdout);

    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
      $line = $this->readLine($stdout, $deadline - microtime(true));
      if (preg_match('/^worker #\d+ \(pid (\d+)\) started$/', $line, $matches) === 1) {
        $pid = (int) $matches[1];
        $this->workerPids[] = $pid;

        return $pid;
      }
    }

    $this->fail('Worker did not start');
  }

  /**
   * @return resource
   */
  private function send(string $command): mixed
  {
    $client = stream_socket_client("tcp://{$this->address}", $errno, $errstr, 1);
    if ($client === false) {
      throw new RuntimeException("Failed to connect: {$errstr} ({$errno})");
    }
    fwrite($client, "{$command}\n");

    return $client;
  }

  /**
   * 1 行読む。期限までに読めないか、相手が閉じたら空文字列
   *
   * @param resource $stream
   */
  private function readLine(mixed $stream, float $timeout): string
  {
    $read = [$stream];
    $write = $except = null;
    $micro = (int) (max(0.0, $timeout) * 1_000_000);
    if (stream_select($read, $write, $except, intdiv($micro, 1_000_000), $micro % 1_000_000) !== 1) {
      return '';
    }

    $line = fgets($stream);

    return $line === false ? '' : rtrim($line, "\n");
  }

  /**
   * 期限までに応答が返った接続の応答だけを集める
   *
   * @param list<resource> $clients
   * @return list<string>
   */
  private function readAll(array $clients, float $timeout): array
  {
    $deadline = microtime(true) + $timeout;
    $responses = [];
    foreach ($clients as $client) {
      $response = $this->readLine($client, $deadline - microtime(true));
      if ($response !== '') {
        $responses[] = $response;
      }
    }

    return $responses;
  }
}
