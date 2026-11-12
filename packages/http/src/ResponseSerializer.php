<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * ResponseInterface を HTTP/1.1 の応答のバイト列にする
 *
 * @internal
 */
final class ResponseSerializer
{
  /** サーバが自動で付けるため、ハンドラの指定は無視するヘッダ */
  private const MANAGED_HEADERS = ['content-length', 'connection'];

  /** 値をカンマで結合できないため、値ごとに行を分けるヘッダ */
  private const MULTI_LINE_HEADERS = ['set-cookie'];

  public static function serialize(ResponseInterface $response): string
  {
    $body = (string) $response->getBody();

    $head = "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} {$response->getReasonPhrase()}\r\n";
    foreach ($response->getHeaders() as $name => $values) {
      $key = strtolower((string) $name);
      if (in_array($key, self::MANAGED_HEADERS, true)) {
        continue;
      }

      $lines = in_array($key, self::MULTI_LINE_HEADERS, true) ? $values : [implode(', ', $values)];
      foreach ($lines as $value) {
        $head .= "{$name}: {$value}\r\n";
      }
    }

    return $head
      . 'Content-Length: ' . strlen($body) . "\r\n"
      . "Connection: close\r\n"
      . "\r\n"
      . $body;
  }
}
