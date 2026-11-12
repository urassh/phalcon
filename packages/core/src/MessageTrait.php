<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Request / ServerRequest / Response に共通する MessageInterface の実装
 *
 * @internal
 */
trait MessageTrait
{
  /** RFC 9110 の token */
  private const HEADER_NAME_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+\-.^_`|~]+\z/';

  /** 改行と制御文字を含まない値 */
  private const HEADER_VALUE_PATTERN = '/\A[\x20\x09\x21-\x7E\x80-\xFF]*\z/';

  private string $protocolVersion = '1.1';

  /** @var array<string, list<string>> 元の綴りのヘッダ名 => 値のリスト */
  private array $headers = [];

  /** @var array<string, string> 小文字化したヘッダ名 => 元の綴り */
  private array $headerNames = [];

  private StreamInterface $body;

  public function getProtocolVersion(): string
  {
    return $this->protocolVersion;
  }

  public function withProtocolVersion(string $version): MessageInterface
  {
    if (preg_match('/\A\d+(?:\.\d+)?\z/', $version) !== 1) {
      throw new InvalidArgumentException("Invalid protocol version: {$version}");
    }

    $new = clone $this;
    $new->protocolVersion = $version;

    return $new;
  }

  /**
   * @return array<string, list<string>>
   */
  public function getHeaders(): array
  {
    return $this->headers;
  }

  public function hasHeader(string $name): bool
  {
    return isset($this->headerNames[strtolower($name)]);
  }

  /**
   * @return list<string>
   */
  public function getHeader(string $name): array
  {
    $original = $this->headerNames[strtolower($name)] ?? null;

    return $original === null ? [] : $this->headers[$original];
  }

  public function getHeaderLine(string $name): string
  {
    return implode(', ', $this->getHeader($name));
  }

  /**
   * @param mixed $value 実行時に検証する
   */
  public function withHeader(string $name, $value): MessageInterface
  {
    $new = clone $this;
    $new->setHeader($name, $value);

    return $new;
  }

  /**
   * @param mixed $value 実行時に検証する
   */
  public function withAddedHeader(string $name, $value): MessageInterface
  {
    $new = clone $this;
    $new->addHeader($name, $value);

    return $new;
  }

  public function withoutHeader(string $name): MessageInterface
  {
    $new = clone $this;
    $new->removeHeader($name);

    return $new;
  }

  public function getBody(): StreamInterface
  {
    return $this->body;
  }

  public function withBody(StreamInterface $body): MessageInterface
  {
    $new = clone $this;
    $new->body = $body;

    return $new;
  }

  /**
   * @param array<mixed> $headers ヘッダ名 => 値 (文字列か文字列のリスト)
   */
  private function setHeaders(array $headers): void
  {
    foreach ($headers as $name => $value) {
      // 数字だけのヘッダ名は配列のキーで int になる
      $this->addHeader((string) $name, $value);
    }
  }

  private function setHeader(string $name, mixed $value): void
  {
    $values = self::normalizeHeaderValue($value);
    $this->removeHeader(self::validateHeaderName($name));
    $this->headerNames[strtolower($name)] = $name;
    $this->headers[$name] = $values;
  }

  private function addHeader(string $name, mixed $value): void
  {
    $values = self::normalizeHeaderValue($value);
    $original = $this->headerNames[strtolower(self::validateHeaderName($name))] ?? null;
    if ($original === null) {
      $this->headerNames[strtolower($name)] = $name;
      $this->headers[$name] = $values;

      return;
    }

    $this->headers[$original] = [...$this->headers[$original], ...$values];
  }

  private function removeHeader(string $name): void
  {
    $lower = strtolower($name);
    $original = $this->headerNames[$lower] ?? null;
    if ($original === null) {
      return;
    }

    unset($this->headers[$original], $this->headerNames[$lower]);
  }

  private static function validateHeaderName(string $name): string
  {
    if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
      throw new InvalidArgumentException("Invalid header name: {$name}");
    }

    return $name;
  }

  /**
   * @return non-empty-list<string>
   */
  private static function normalizeHeaderValue(mixed $value): array
  {
    $values = is_array($value) ? array_values($value) : [$value];
    if ($values === []) {
      throw new InvalidArgumentException('Header value must not be an empty array');
    }

    $normalized = [];
    foreach ($values as $item) {
      if (!is_string($item) && !is_int($item) && !is_float($item)) {
        throw new InvalidArgumentException('Header value must be a string, got ' . get_debug_type($item));
      }

      // 前後の空白 (OWS) は値に含めない
      $item = trim((string) $item, " \t");
      if (preg_match(self::HEADER_VALUE_PATTERN, $item) !== 1) {
        throw new InvalidArgumentException("Invalid header value: {$item}");
      }
      $normalized[] = $item;
    }

    return $normalized;
  }
}
