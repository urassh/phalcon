<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\ValueObject;

use InvalidArgumentException;

final class Request
{
  public const HEADER_TERMINATOR = "\r\n\r\n";

  private const LINE_TERMINATOR = "\r\n";

  public function __construct(
    private readonly string $method,
    private readonly string $target,
    private readonly string $version,
    private readonly Headers $headers,
    private readonly string $body,
  ) {
  }

  /**
   * @throws InvalidArgumentException リクエストラインが不正な場合
   */
  public static function parse(string $data): self
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

    $headers = Headers::parse($headerLines);

    return new self($method, $target, $version, $headers, substr($rest, 0, $headers->contentLength()));
  }

  /**
   * ヘッダ部をリクエストラインとヘッダフィールドの行に分ける
   *
   * @return array{string, string}
   */
  public static function splitHead(string $head): array
  {
    $lineEnd = strpos($head, self::LINE_TERMINATOR);
    if ($lineEnd === false) {
      return [$head, ''];
    }

    return [substr($head, 0, $lineEnd), substr($head, $lineEnd + strlen(self::LINE_TERMINATOR))];
  }

  public function method(): string
  {
    return $this->method;
  }

  public function target(): string
  {
    return $this->target;
  }

  public function version(): string
  {
    return $this->version;
  }

  public function headers(): Headers
  {
    return $this->headers;
  }

  public function body(): string
  {
    return $this->body;
  }
}
