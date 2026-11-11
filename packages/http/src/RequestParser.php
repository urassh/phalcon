<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Urassh\Phalcon\Core\Psr17Factory;

/**
 * 生のリクエストのバイト列を ServerRequestInterface にする
 *
 * @internal
 */
final class RequestParser
{
  public const HEADER_TERMINATOR = "\r\n\r\n";

  private const LINE_TERMINATOR = "\r\n";

  public function __construct(
    private readonly Psr17Factory $factory = new Psr17Factory(),
  ) {
  }

  /**
   * ヘッダ部と Content-Length 分のボディが揃ったか
   */
  public static function isComplete(string $data): bool
  {
    $headerEnd = strpos($data, self::HEADER_TERMINATOR);
    if ($headerEnd === false) {
      return false;
    }

    $bodyOffset = $headerEnd + strlen(self::HEADER_TERMINATOR);
    [, $headerLines] = self::splitHead(substr($data, 0, $headerEnd));
    $contentLength = Headers::parse($headerLines)->contentLength();

    return strlen($data) >= $bodyOffset + $contentLength;
  }

  /**
   * @throws InvalidArgumentException リクエストライン、ヘッダ、リクエストターゲットが不正な場合
   */
  public function parse(string $data): ServerRequestInterface
  {
    $headerEnd = strpos($data, self::HEADER_TERMINATOR);
    $head = $headerEnd === false ? $data : substr($data, 0, $headerEnd);
    $rest = $headerEnd === false ? '' : substr($data, $headerEnd + strlen(self::HEADER_TERMINATOR));

    [$requestLine, $headerLines] = self::splitHead($head);

    $parts = explode(' ', $requestLine);
    if (count($parts) !== 3 || in_array('', $parts, true)) {
      throw new InvalidArgumentException("Malformed request line: {$requestLine}");
    }
    [$method, $target, $version] = $parts;

    if (preg_match('#\AHTTP/(\d\.\d)\z#', $version, $matches) !== 1) {
      throw new InvalidArgumentException("Unsupported HTTP version: {$version}");
    }

    $headers = Headers::parse($headerLines);

    $request = $this->factory
      ->createServerRequest($method, $target)
      ->withRequestTarget($target)
      ->withProtocolVersion($matches[1])
      ->withBody($this->factory->createStream(substr($rest, 0, $headers->contentLength())));

    foreach ($headers->withOriginalNames() as $name => $values) {
      $request = $request->withHeader($name, $values);
    }

    return $request;
  }

  /**
   * ヘッダ部をリクエストラインとヘッダフィールドの行に分ける
   *
   * @return array{string, string}
   */
  private static function splitHead(string $head): array
  {
    $lineEnd = strpos($head, self::LINE_TERMINATOR);
    if ($lineEnd === false) {
      return [$head, ''];
    }

    return [substr($head, 0, $lineEnd), substr($head, $lineEnd + strlen(self::LINE_TERMINATOR))];
  }
}
