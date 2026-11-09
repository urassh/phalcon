<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\Linux;

use FFI\CData;
use Urassh\Phalcon\Internal\Server\Port\Poller;
use Urassh\Phalcon\Internal\Server\Port\Readiness;

/**
 * @internal
 */
final class EpollPoller implements Poller
{
  private const MAX_EVENTS = 64;

  // エッジトリガなら EPOLLOUT を常に登録しておいても通知が鳴り続けないので、EPOLL_CTL_MOD は使わない
  private const EVENTS = Libc::EPOLLIN | Libc::EPOLLOUT | Libc::EPOLLRDHUP | Libc::EPOLLET;

  private readonly int $epfd;
  private readonly CData $events;

  public function __construct(
    private readonly Libc $libc,
  ) {
    $this->epfd = $libc->epollCreate();
    $this->events = $libc->newEpollEvents(self::MAX_EVENTS);
  }

  public function __destruct()
  {
    $this->libc->close($this->epfd);
  }

  public function add(int $fd): void
  {
    $this->libc->epollAdd($this->epfd, $fd, self::EVENTS);
  }

  public function remove(int $fd): void
  {
    $this->libc->epollRemove($this->epfd, $fd);
  }

  public function wait(?int $timeoutMs): array
  {
    $readiness = [];
    foreach ($this->libc->epollWait($this->epfd, $this->events, self::MAX_EVENTS, $timeoutMs ?? -1) as $event) {
      $readiness[] = new Readiness(
        fd: $event['fd'],
        readable: ($event['events'] & (Libc::EPOLLIN | Libc::EPOLLRDHUP)) !== 0,
        writable: ($event['events'] & Libc::EPOLLOUT) !== 0,
        hangup: ($event['events'] & (Libc::EPOLLERR | Libc::EPOLLHUP)) !== 0,
      );
    }

    return $readiness;
  }
}
