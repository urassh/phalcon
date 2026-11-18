<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 のハンドラを動かすもの
 *
 * HTTP サーバとして常駐するもの (urassh/phalcon-http の HttpServer) も、
 * SAPI から 1 リクエストごとに呼ばれるもの (SapiRunner) もこれを実装する
 */
interface Runner
{
  /**
   * ハンドラでリクエストを処理する。常駐する実装はプロセスが終了するまで戻らない (戻り値の型を never にしてよい)
   */
  public function run(RequestHandlerInterface $handler): void;
}
