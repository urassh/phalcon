<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

/**
 * HttpServer の下で待ち受けと接続の読み書きを受け持つサーバ層
 *
 * 接続ごとに受け取ったバイト列をため、`HttpProtocol::isComplete()` が true を返したら
 * `HttpProtocol::respond()` の戻り値を書き込んで接続を閉じる。
 * どこで待ち受けるか、どう並行に処理するかは実装ごとの設定で、http は関知しない。既定の実装は PhalconServerTransport
 */
interface Transport
{
  /**
   * サーバを起動する。プロセスが終了するまで戻らない
   */
  public function serve(HttpProtocol $protocol): never;
}
