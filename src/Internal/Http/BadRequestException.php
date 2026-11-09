<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Http;

use RuntimeException;

/**
 * ハンドラの処理を打ち切って 400 Bad Request を返すための例外
 *
 * @internal
 */
final class BadRequestException extends RuntimeException
{
}
