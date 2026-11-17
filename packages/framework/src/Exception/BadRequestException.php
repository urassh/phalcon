<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 400 Bad Request。リクエストの形式や値が不正な場合
 */
final class BadRequestException extends HttpException
{
  /**
   * @param string $message 空なら理由句
   * @param array<string, string> $headers
   */
  public function __construct(string $message = '', array $headers = [], ?Throwable $previous = null)
  {
    parent::__construct(400, $message, $headers, $previous);
  }
}
