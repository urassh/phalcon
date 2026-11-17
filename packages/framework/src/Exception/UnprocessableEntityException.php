<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 422 Unprocessable Entity。形式は正しいがバリデーションに失敗した場合
 */
final class UnprocessableEntityException extends HttpException
{
  /**
   * @param string $message 空なら理由句
   * @param array<string, string> $headers
   */
  public function __construct(string $message = '', array $headers = [], ?Throwable $previous = null)
  {
    parent::__construct(422, $message, $headers, $previous);
  }
}
