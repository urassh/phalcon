<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http\ValueObject;

final class Headers
{
  /**
   * @param array<string, list<string>> $values 小文字化したヘッダ名 => 値のリスト
   */
  private function __construct(
    private readonly array $values,
  ) {
  }

  /**
   * ヘッダフィールドの行 (リクエストラインを除く) をパースする
   */
  public static function parse(string $raw): self
  {
    $values = [];

    foreach (explode("\r\n", $raw) as $line) {
      $pos = strpos($line, ':');
      if ($pos === false) {
        continue;
      }

      $name = strtolower(trim(substr($line, 0, $pos)));
      if ($name === '') {
        continue;
      }

      $values[$name][] = trim(substr($line, $pos + 1));
    }

    return new self($values);
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
   * @return array<string, list<string>>
   */
  public function all(): array
  {
    return $this->values;
  }

  public function contentLength(): int
  {
    $value = $this->get('Content-Length');

    return $value !== null && ctype_digit($value) ? (int) $value : 0;
  }
}
