<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\View;

use RuntimeException;

/**
 * テンプレートを描画できなかったときの例外。レスポンスは 500 になる
 *
 * @internal
 */
final class ViewException extends RuntimeException
{
}
