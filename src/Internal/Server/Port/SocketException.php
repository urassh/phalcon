<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Port;

use RuntimeException;

/**
 * WouldBlock 以外のソケットのエラー
 *
 * @internal
 */
final class SocketException extends RuntimeException
{
}
