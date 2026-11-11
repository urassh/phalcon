<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Server\Platform\Linux;

use FFI;
use FFI\CData;
use Urassh\Phalcon\Internal\Server\Port\SocketException;

/**
 * FFI で呼ぶ glibc の関数と、Linux の定数
 *
 * 構造体は C 側で定義せず、pack / unpack で組み立てたバイト列として受け渡す
 *
 * @internal
 */
final class Libc
{
  public const AF_INET = 2;
  public const SOCK_STREAM = 1;
  public const SOCK_NONBLOCK = 0x800;
  public const SOCK_CLOEXEC = 0x80000;
  public const SOL_SOCKET = 1;
  public const SO_REUSEADDR = 2;
  public const MSG_NOSIGNAL = 0x4000;

  public const EINTR = 4;
  public const EAGAIN = 11;
  public const ECONNABORTED = 103;

  public const EPOLL_CLOEXEC = 0x80000;
  public const EPOLL_CTL_ADD = 1;
  public const EPOLL_CTL_DEL = 2;
  public const EPOLLIN = 0x001;
  public const EPOLLOUT = 0x004;
  public const EPOLLERR = 0x008;
  public const EPOLLHUP = 0x010;
  public const EPOLLRDHUP = 0x2000;
  public const EPOLLET = 1 << 31;

  private const LIBRARY = 'libc.so.6';
  private const READ_BUFFER_SIZE = 65536;
  private const SOCKADDR_IN_SIZE = 16;

  private const CDEF = <<<'C'
    typedef unsigned int socklen_t;
    typedef long ssize_t;
    typedef unsigned long size_t;

    int socket(int domain, int type, int protocol);
    int setsockopt(int fd, int level, int optname, const char *optval, socklen_t optlen);
    int bind(int fd, const char *addr, socklen_t len);
    int listen(int fd, int backlog);
    int getsockname(int fd, char *addr, socklen_t *len);
    int accept4(int fd, void *addr, socklen_t *len, int flags);
    ssize_t recv(int fd, char *buf, size_t n, int flags);
    ssize_t send(int fd, const char *buf, size_t n, int flags);
    int close(int fd);
    int epoll_create1(int flags);
    int epoll_ctl(int epfd, int op, int fd, const char *event);
    int epoll_wait(int epfd, char *events, int maxevents, int timeout);
    int *__errno_location(void);
    const char *strerror(int errnum);
    C;

  private readonly CData $readBuffer;

  /**
   * @param 4|8 $epollDataOffset struct epoll_event の data の位置。
   *                             x86_64 だけ packed なので 4、aarch64 などではパディングが入って 8
   */
  private function __construct(
    private readonly FFI $ffi,
    private readonly int $epollDataOffset,
  ) {
    $this->readBuffer = $this->new('char[' . self::READ_BUFFER_SIZE . ']');
  }

  /**
   * @throws FFI\Exception FFI が無効、または libc を読み込めない
   */
  public static function load(): self
  {
    return new self(FFI::cdef(self::CDEF, self::LIBRARY), php_uname('m') === 'x86_64' ? 4 : 8);
  }

  /**
   * @throws SocketException
   */
  public function listenTcp(string $host, int $port, int $backlog): int
  {
    $fd = $this->syscall('socket', self::AF_INET, self::SOCK_STREAM | self::SOCK_NONBLOCK | self::SOCK_CLOEXEC, 0);

    try {
      $this->syscall('setsockopt', $fd, self::SOL_SOCKET, self::SO_REUSEADDR, pack('l', 1), 4);

      $addr = $this->sockaddr($host, $port);
      $this->check($this->call('bind', $fd, $addr, strlen($addr)), "bind {$host}:{$port}");
      $this->syscall('listen', $fd, $backlog);
    } catch (SocketException $e) {
      $this->close($fd);
      throw $e;
    }

    return $fd;
  }

  /**
   * @throws SocketException
   */
  public function localAddress(int $fd): string
  {
    $addr = $this->new('char[' . self::SOCKADDR_IN_SIZE . ']');
    $len = $this->new('socklen_t[1]');
    $len[0] = self::SOCKADDR_IN_SIZE;
    $this->syscall('getsockname', $fd, $addr, $len);

    $bytes = FFI::string($addr, self::SOCKADDR_IN_SIZE);
    $ip = inet_ntop(substr($bytes, 4, 4));

    return ($ip === false ? 'unknown' : $ip) . ':' . $this->unpackInt('n', substr($bytes, 2, 2));
  }

  /**
   * @return ?int null なら受け付け待ちの接続がない
   * @throws SocketException
   */
  public function accept(int $fd): ?int
  {
    while (true) {
      $client = $this->int($this->call('accept4', $fd, null, null, self::SOCK_NONBLOCK | self::SOCK_CLOEXEC));
      if ($client >= 0) {
        return $client;
      }

      $errno = $this->errno();
      if ($errno === self::EAGAIN) {
        return null;
      }
      // 受け付ける前に相手が切断した接続は飛ばして次を見る
      if ($errno !== self::EINTR && $errno !== self::ECONNABORTED) {
        throw $this->error('accept4', $errno);
      }
    }
  }

  /**
   * @return ?string null なら WouldBlock、'' なら EOF
   * @throws SocketException
   */
  public function recv(int $fd, int $maxBytes): ?string
  {
    $size = min($maxBytes, self::READ_BUFFER_SIZE);

    while (true) {
      $read = $this->int($this->call('recv', $fd, $this->readBuffer, $size, 0));
      if ($read >= 0) {
        return FFI::string($this->readBuffer, $read);
      }

      $errno = $this->errno();
      if ($errno === self::EAGAIN) {
        return null;
      }
      if ($errno !== self::EINTR) {
        throw $this->error('recv', $errno);
      }
    }
  }

  /**
   * @return int 書き込めたバイト数。0 なら WouldBlock
   * @throws SocketException
   */
  public function send(int $fd, string $data): int
  {
    while (true) {
      // 相手が切断済みでも SIGPIPE でプロセスが落ちないようにする
      $written = $this->int($this->call('send', $fd, $data, strlen($data), self::MSG_NOSIGNAL));
      if ($written >= 0) {
        return $written;
      }

      $errno = $this->errno();
      if ($errno === self::EAGAIN) {
        return 0;
      }
      if ($errno !== self::EINTR) {
        throw $this->error('send', $errno);
      }
    }
  }

  public function close(int $fd): void
  {
    $this->call('close', $fd);
  }

  /**
   * @throws SocketException
   */
  public function epollCreate(): int
  {
    return $this->syscall('epoll_create1', self::EPOLL_CLOEXEC);
  }

  /**
   * @throws SocketException
   */
  public function epollAdd(int $epfd, int $fd, int $events): void
  {
    $event = str_pad(pack('V', $events), $this->epollDataOffset, "\0") . pack('P', $fd);
    $this->check($this->call('epoll_ctl', $epfd, self::EPOLL_CTL_ADD, $fd, $event), 'epoll_ctl(ADD)');
  }

  public function epollRemove(int $epfd, int $fd): void
  {
    // すでに close 済みなどで失敗しても、監視から外れていることに変わりはない
    $this->call('epoll_ctl', $epfd, self::EPOLL_CTL_DEL, $fd, null);
  }

  public function newEpollEvents(int $count): CData
  {
    return $this->new('char[' . $count * $this->epollEventSize() . ']');
  }

  /**
   * @return list<array{fd: int, events: int}> シグナルで中断された場合は空
   * @throws SocketException
   */
  public function epollWait(int $epfd, CData $buffer, int $maxEvents, int $timeoutMs): array
  {
    $count = $this->int($this->call('epoll_wait', $epfd, $buffer, $maxEvents, $timeoutMs));
    if ($count < 0) {
      $errno = $this->errno();
      if ($errno === self::EINTR) {
        return [];
      }

      throw $this->error('epoll_wait', $errno);
    }

    $size = $this->epollEventSize();
    $bytes = FFI::string($buffer, $count * $size);
    $events = [];
    for ($i = 0; $i < $count; $i++) {
      $offset = $i * $size;
      $events[] = [
        'fd' => $this->unpackInt('P', substr($bytes, $offset + $this->epollDataOffset, 8)),
        'events' => $this->unpackInt('V', substr($bytes, $offset, 4)),
      ];
    }

    return $events;
  }

  /**
   * @return 12|16
   */
  private function epollEventSize(): int
  {
    return $this->epollDataOffset + 8;
  }

  /**
   * struct sockaddr_in のバイト列 (sin_family はホストのバイト順、ポートとアドレスはネットワークバイト順)
   */
  private function sockaddr(string $host, int $port): string
  {
    // ホスト名は先に IPv4 に解決する
    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? gethostbyname($host) : $host;
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 4) {
      throw new SocketException("Invalid IPv4 address: {$host}");
    }

    return pack('vn', self::AF_INET, $port) . $packed . str_repeat("\0", 8);
  }

  private function new(string $type): CData
  {
    return $this->ffi->new($type);
  }

  private function call(string $function, mixed ...$args): mixed
  {
    return $this->ffi->{$function}(...$args);
  }

  /**
   * call() + check() のショートハンド。関数名をそのままエラー時の操作名に使う
   *
   * @throws SocketException
   */
  private function syscall(string $function, mixed ...$args): int
  {
    return $this->check($this->call($function, ...$args), $function);
  }

  /**
   * @throws SocketException 戻り値が負なら errno をもとに例外にする
   */
  private function check(mixed $result, string $operation): int
  {
    $value = $this->int($result);
    if ($value < 0) {
      throw $this->error($operation, $this->errno());
    }

    return $value;
  }

  private function errno(): int
  {
    $pointer = $this->call('__errno_location');
    if (!$pointer instanceof CData) {
      throw new SocketException('Failed to read errno');
    }

    return $this->int($pointer[0]);
  }

  private function error(string $operation, int $errno): SocketException
  {
    $message = $this->call('strerror', $errno);
    $text = match (true) {
      is_string($message) => $message,
      $message instanceof CData => FFI::string($message),
      default => 'unknown error',
    };

    return new SocketException("{$operation} failed: {$text} ({$errno})", $errno);
  }

  private function unpackInt(string $format, string $bytes): int
  {
    $values = unpack($format, $bytes);

    return $this->int($values === false ? null : ($values[1] ?? null));
  }

  private function int(mixed $value): int
  {
    if (!is_int($value)) {
      throw new SocketException('Unexpected FFI return value: ' . get_debug_type($value));
    }

    return $value;
  }
}
