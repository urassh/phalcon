<?php

declare(strict_types=1);

// ServerTest から別プロセスで起動する。引数はワーカ数
//
// 1 行受け取ったら応答する。"slow" は 2 秒ブロックしてから、"die" はワーカごと終了する

require __DIR__ . '/../../vendor/autoload.php';

use Urassh\Phalcon\Server\Protocol;
use Urassh\Phalcon\Server\Server;

$protocol = new class () implements Protocol {
  public function isComplete(string $data): bool
  {
    return str_contains($data, "\n");
  }

  public function respond(string $data): string
  {
    $command = trim($data);
    if ($command === 'slow') {
      sleep(2);
    }
    if ($command === 'die') {
      exit(1);
    }

    return "{$command} " . getmypid() . "\n";
  }
};

(new Server())->serve($protocol, '127.0.0.1', 0, (int) ($argv[1] ?? 1));
