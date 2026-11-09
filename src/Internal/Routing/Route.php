<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Routing;

use Closure;
use InvalidArgumentException;

/**
 * @internal
 */
final class Route
{
  private const PARAM_PATTERN = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

  private readonly string $regex;

  public function __construct(
    private readonly string $method,
    string $path,
    private readonly Closure $handler,
  ) {
    $this->regex = self::compile($path);
  }

  /**
   * パスパターンを正規表現に変換する。`{name}` は `/` を含まない 1 セグメントにマッチする
   */
  private static function compile(string $path): string
  {
    $parts = preg_split(self::PARAM_PATTERN, $path, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
      throw new InvalidArgumentException("Invalid path pattern: {$path}");
    }

    // 偶数番目がリテラル、奇数番目がパラメータ名
    $regex = '';
    foreach ($parts as $i => $part) {
      $regex .= $i % 2 === 0
        ? preg_quote($part, '#')
        : "(?P<{$part}>[^/]+)";
    }

    return "#\\A{$regex}\\z#";
  }

  public function method(): string
  {
    return $this->method;
  }

  public function handler(): Closure
  {
    return $this->handler;
  }

  /**
   * パスがマッチすればルートパラメータを、しなければ null を返す
   *
   * @return array<string, string>|null
   */
  public function match(string $path): ?array
  {
    if (preg_match($this->regex, $path, $matches) !== 1) {
      return null;
    }

    $params = [];
    foreach ($matches as $name => $value) {
      if (is_string($name)) {
        $params[$name] = rawurldecode($value);
      }
    }

    return $params;
  }
}
