<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use Throwable;

/**
 * 405 Method Not Allowed。許可するメソッドを `Allow` ヘッダで返す
 */
final class MethodNotAllowedException extends HttpException
{
  /**
   * @param list<string> $allowed 許可するメソッド
   * @param string $message 空なら理由句
   */
  public function __construct(array $allowed, string $message = '', ?Throwable $previous = null)
  {
    parent::__construct(405, $message, ['Allow' => implode(', ', $allowed)], $previous);
  }
}
