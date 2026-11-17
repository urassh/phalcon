<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 409 Conflict。リソースの現在の状態と衝突する場合 (重複登録など)
 */
final class ConflictException extends HttpException
{
  /**
   * @param string $message 空なら理由句
   * @param array<string, string> $headers
   */
  public function __construct(string $message = '', array $headers = [], ?Throwable $previous = null)
  {
    parent::__construct(409, $message, $headers, $previous);
  }
}
