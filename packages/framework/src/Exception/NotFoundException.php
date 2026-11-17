<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 404 Not Found。リソースが存在しない場合
 */
final class NotFoundException extends HttpException
{
  /**
   * @param string $message 空なら理由句
   * @param array<string, string> $headers
   */
  public function __construct(string $message = '', array $headers = [], ?Throwable $previous = null)
  {
    parent::__construct(404, $message, $headers, $previous);
  }
}
