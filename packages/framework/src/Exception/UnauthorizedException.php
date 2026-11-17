<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 401 Unauthorized。認証されていない場合。`WWW-Authenticate` は $headers で渡す
 */
final class UnauthorizedException extends HttpException
{
  /**
   * @param string $message 空なら理由句
   * @param array<string, string> $headers
   */
  public function __construct(string $message = '', array $headers = [], ?Throwable $previous = null)
  {
    parent::__construct(401, $message, $headers, $previous);
  }
}
