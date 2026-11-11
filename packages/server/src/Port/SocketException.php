<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Port;

use RuntimeException;

/**
 * WouldBlock 以外のソケットのエラー
 */
final class SocketException extends RuntimeException
{
}
