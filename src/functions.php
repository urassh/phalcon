<?php

declare(strict_types=1);

// 他のライブラリが先に e() を定義している場合はそちらを使う
if (!function_exists('e')) {
  /**
   * テンプレートに出力する値を HTML エスケープする
   */
  function e(mixed $value): string
  {
    if (!is_scalar($value) && $value !== null && !$value instanceof Stringable) {
      throw new InvalidArgumentException('e() expects a scalar or Stringable, got ' . get_debug_type($value));
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
