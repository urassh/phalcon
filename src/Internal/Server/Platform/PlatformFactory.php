<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform;

use FFI;
use Urassh\Phalcon\Internal\Server\Platform\Linux\Libc;
use Urassh\Phalcon\Internal\Server\Platform\Linux\LinuxPlatform;
use Urassh\Phalcon\Internal\Server\Platform\StreamSelect\StreamSelectPlatform;
use Urassh\Phalcon\Internal\Server\Port\Platform;

/**
 * @internal
 */
final class PlatformFactory
{
  /**
   * Linux で FFI が使えれば epoll、そうでなければ stream_select を使う
   */
  public static function detect(): Platform
  {
    if (PHP_OS_FAMILY === 'Linux' && extension_loaded('ffi')) {
      try {
        return new LinuxPlatform(Libc::load());
      } catch (FFI\Exception) {
        // ffi.enable で禁止されているなど
      }
    }

    return new StreamSelectPlatform();
  }
}
