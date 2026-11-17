<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

use RuntimeException;
use Urassh\Phalcon\Server\Platform\SystemClock;
use Urassh\Phalcon\Server\Port\Platform;
use Urassh\Phalcon\Server\Port\SocketException;

/**
 * ワーカを fork して監視する。各ワーカは SO_REUSEPORT 付きで同じアドレスに listen し、
 * 接続の割り振りはカーネルに任せる
 *
 * pcntl と posix の拡張が必要
 *
 * @internal
 */
final class Supervisor
{
  /** 起動からこの秒数以内に終了したワーカは、起動に失敗したとみなす */
  private const QUICK_EXIT_SECONDS = 1.0;

  /** 起動直後の終了がこの回数続いたら、再起動を諦めて終了する */
  private const MAX_QUICK_EXITS = 5;

  private const SIGNALS = [SIGTERM, SIGINT];

  /** @var array<int, array{number: int, startedAt: float}> pid => ワーカ */
  private array $workers = [];

  public function __construct(
    private readonly Platform $platform,
    private readonly Protocol $protocol,
    private readonly string $host,
    private readonly int $port,
    private readonly int $count,
  ) {
  }

  /**
   * @throws SocketException 待ち受けを始められない
   */
  public function run(): never
  {
    $port = $this->resolvePort();

    pcntl_async_signals(true);
    foreach (self::SIGNALS as $signal) {
      // waitpid を再開させず、中断させてハンドラを動かす
      pcntl_signal($signal, fn () => $this->shutdown(), false);
    }

    for ($number = 1; $number <= $this->count; $number++) {
      $this->spawn($number, $port);
    }

    $quickExits = 0;
    while (true) {
      $pid = pcntl_waitpid(-1, $status);
      $worker = $this->workers[$pid] ?? null;
      if ($worker === null || !is_int($status)) {
        continue;
      }
      unset($this->workers[$pid]);

      echo "worker #{$worker['number']} (pid {$pid}) exited: {$this->describe($status)}\n";

      if (microtime(true) - $worker['startedAt'] < self::QUICK_EXIT_SECONDS) {
        $quickExits++;
        if ($quickExits >= self::MAX_QUICK_EXITS) {
          fwrite(STDERR, "Workers keep exiting right after start; giving up\n");
          $this->stopWorkers();
          exit(1);
        }
      } else {
        $quickExits = 0;
      }

      $this->spawn($worker['number'], $port);
    }
  }

  /**
   * 一度 listen して実際のポートを確定させる。失敗はワーカではなくここで例外にする
   *
   * listener は fork 前に閉じる。開いたままだとカーネルがこのソケットにも接続を振り、誰も accept しない
   */
  private function resolvePort(): int
  {
    $listener = $this->platform->listen($this->host, $this->port, reusePort: true);
    $address = $listener->localAddress();
    $listener->close();

    echo "Listening on {$address} ({$this->count} workers)\n";
    // バッファに残っていると、fork 後にワーカ側でも出力されてしまう
    flush();

    return (int) substr($address, (int) strrpos($address, ':') + 1);
  }

  private function spawn(int $number, int $port): void
  {
    // fork と記録の間にシグナルを処理すると、記録前のワーカを止め損ねるので保留しておく
    pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS);

    $pid = pcntl_fork();
    if ($pid === 0) {
      foreach (self::SIGNALS as $signal) {
        pcntl_signal($signal, SIG_DFL);
      }
      pcntl_sigprocmask(SIG_UNBLOCK, self::SIGNALS);

      $this->work($number, $port);
    }

    if ($pid < 0) {
      pcntl_sigprocmask(SIG_UNBLOCK, self::SIGNALS);
      throw new RuntimeException('pcntl_fork failed: ' . pcntl_strerror(pcntl_get_last_error()));
    }

    $this->workers[$pid] = ['number' => $number, 'startedAt' => microtime(true)];
    pcntl_sigprocmask(SIG_UNBLOCK, self::SIGNALS);
  }

  /**
   * ワーカの本体。Poller は fork 後に作る (epoll の fd を親子で共有すると通知が分かれてしまう)
   */
  private function work(int $number, int $port): never
  {
    try {
      $listener = $this->platform->listen($this->host, $port, reusePort: true);
      $loop = new EventLoop($listener, $this->platform->createPoller(), $this->protocol, new SystemClock());
    } catch (SocketException $e) {
      fwrite(STDERR, "worker #{$number}: {$e->getMessage()}\n");
      exit(1);
    }

    echo "worker #{$number} (pid " . getmypid() . ") started\n";
    flush();

    $loop->run();
  }

  /**
   * SIGTERM / SIGINT を受けたら、全ワーカを止めて回収してから終了する
   */
  private function shutdown(): never
  {
    foreach (self::SIGNALS as $signal) {
      pcntl_signal($signal, SIG_IGN);
    }

    $this->stopWorkers();
    exit(0);
  }

  private function stopWorkers(): void
  {
    foreach (array_keys($this->workers) as $pid) {
      posix_kill($pid, SIGTERM);
    }
    foreach (array_keys($this->workers) as $pid) {
      pcntl_waitpid($pid, $status);
    }
    $this->workers = [];
  }

  private function describe(int $status): string
  {
    if (pcntl_wifsignaled($status)) {
      return 'signal ' . pcntl_wtermsig($status);
    }

    return 'status ' . pcntl_wexitstatus($status);
  }
}
