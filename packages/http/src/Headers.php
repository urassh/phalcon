<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

/**
 * @internal
 */
final class Headers
{
  /**
   * @param array<string, list<string>> $values 小文字化したヘッダ名 => 値のリスト
   * @param array<string, string> $names 小文字化したヘッダ名 => 最初に現れたときの綴り
   */
  private function __construct(
    private readonly array $values,
    private readonly array $names,
  ) {
  }

  /**
   * ヘッダフィールドの行 (リクエストラインを除く) をパースする
   */
  public static function parse(string $raw): self
  {
    $values = [];
    $names = [];

    foreach (explode("\r\n", $raw) as $line) {
      $pos = strpos($line, ':');
      if ($pos === false) {
        continue;
      }

      $name = trim(substr($line, 0, $pos));
      if ($name === '') {
        continue;
      }

      $key = strtolower($name);
      $names[$key] ??= $name;
      $values[$key][] = trim(substr($line, $pos + 1));
    }

    return new self($values, $names);
  }

  public function has(string $name): bool
  {
    return isset($this->values[strtolower($name)]);
  }

  public function get(string $name): ?string
  {
    return $this->values[strtolower($name)][0] ?? null;
  }

  /**
   * @return list<string>
   */
  public function getAll(string $name): array
  {
    return $this->values[strtolower($name)] ?? [];
  }

  /**
   * @return array<string, list<string>> 小文字化したヘッダ名 => 値のリスト
   */
  public function all(): array
  {
    return $this->values;
  }

  /**
   * @return array<string, list<string>> 元の綴りのヘッダ名 => 値のリスト
   */
  public function withOriginalNames(): array
  {
    $headers = [];
    foreach ($this->values as $key => $values) {
      $headers[$this->names[$key]] = $values;
    }

    return $headers;
  }

  public function contentLength(): int
  {
    $value = $this->get('Content-Length');

    return $value !== null && ctype_digit($value) ? (int) $value : 0;
  }
}
