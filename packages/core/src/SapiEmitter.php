<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * ResponseInterface を header() と echo で PHP-FPM などの SAPI に書き出す
 *
 * ServerRequest::fromGlobals() と組み合わせ、1 リクエストごとに起動するスクリプトから PSR-15 のハンドラを動かす
 */
final class SapiEmitter
{
  /**
   * @throws RuntimeException すでにヘッダか本文が出力されている
   */
  public function emit(ResponseInterface $response): void
  {
    if (headers_sent($file, $line)) {
      throw new RuntimeException("Headers already sent in {$file}:{$line}");
    }

    $status = $response->getStatusCode();
    $reason = $response->getReasonPhrase();
    header(
      "HTTP/{$response->getProtocolVersion()} {$status}" . ($reason === '' ? '' : " {$reason}"),
      true,
      $status,
    );

    foreach ($response->getHeaders() as $name => $values) {
      // 1 つ目で PHP が既定で付けるヘッダ (Content-Type など) を置き換え、2 つ目からは行を足す
      $replace = true;
      foreach ($values as $value) {
        header("{$name}: {$value}", $replace);
        $replace = false;
      }
    }

    echo $response->getBody();
  }
}
