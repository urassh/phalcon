<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
  private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

  /** パスで使える文字と、エンコード済みの % 以外をエンコードする */
  private const PATH_PATTERN = '/(?:[^A-Za-z0-9_\-.~!$&\'()*+,;=%:@\/]++|%(?![A-Fa-f0-9]{2}))/';

  /** クエリとフラグメントでは ? も使える */
  private const QUERY_PATTERN = '/(?:[^A-Za-z0-9_\-.~!$&\'()*+,;=%:@\/?]++|%(?![A-Fa-f0-9]{2}))/';

  private string $scheme = '';
  private string $userInfo = '';
  private string $host = '';
  private ?int $port = null;
  private string $path = '';
  private string $query = '';
  private string $fragment = '';

  /**
   * origin-form (/path?query) と absolute-form (http://host/path) のどちらも受け付ける
   *
   * @throws InvalidArgumentException URI として解釈できない場合
   */
  public function __construct(string $uri = '')
  {
    if ($uri === '') {
      return;
    }

    $parts = parse_url($uri);
    if ($parts === false) {
      throw new InvalidArgumentException("Unable to parse URI: {$uri}");
    }

    $this->scheme = strtolower($parts['scheme'] ?? '');
    $this->userInfo = $parts['user'] ?? '';
    if (isset($parts['pass'])) {
      $this->userInfo .= ':' . $parts['pass'];
    }
    $this->host = strtolower($parts['host'] ?? '');
    $this->port = $this->filterPort($parts['port'] ?? null);
    $this->path = self::encode(self::PATH_PATTERN, $parts['path'] ?? '');
    $this->query = self::encode(self::QUERY_PATTERN, $parts['query'] ?? '');
    $this->fragment = self::encode(self::QUERY_PATTERN, $parts['fragment'] ?? '');
  }

  public function getScheme(): string
  {
    return $this->scheme;
  }

  public function getAuthority(): string
  {
    if ($this->host === '') {
      return '';
    }

    $authority = $this->userInfo === '' ? $this->host : "{$this->userInfo}@{$this->host}";

    return $this->port === null ? $authority : "{$authority}:{$this->port}";
  }

  public function getUserInfo(): string
  {
    return $this->userInfo;
  }

  public function getHost(): string
  {
    return $this->host;
  }

  public function getPort(): ?int
  {
    return $this->port;
  }

  public function getPath(): string
  {
    return $this->path;
  }

  public function getQuery(): string
  {
    return $this->query;
  }

  public function getFragment(): string
  {
    return $this->fragment;
  }

  public function withScheme(string $scheme): UriInterface
  {
    $new = clone $this;
    $new->scheme = strtolower($scheme);
    $new->port = $new->filterPort($new->port);

    return $new;
  }

  public function withUserInfo(string $user, ?string $password = null): UriInterface
  {
    $new = clone $this;
    $new->userInfo = $password === null || $password === '' ? $user : "{$user}:{$password}";

    return $new;
  }

  public function withHost(string $host): UriInterface
  {
    $new = clone $this;
    $new->host = strtolower($host);

    return $new;
  }

  public function withPort(?int $port): UriInterface
  {
    $new = clone $this;
    $new->port = $new->filterPort($port);

    return $new;
  }

  public function withPath(string $path): UriInterface
  {
    $new = clone $this;
    $new->path = self::encode(self::PATH_PATTERN, $path);

    return $new;
  }

  public function withQuery(string $query): UriInterface
  {
    $new = clone $this;
    $new->query = self::encode(self::QUERY_PATTERN, $query);

    return $new;
  }

  public function withFragment(string $fragment): UriInterface
  {
    $new = clone $this;
    $new->fragment = self::encode(self::QUERY_PATTERN, $fragment);

    return $new;
  }

  public function __toString(): string
  {
    $uri = $this->scheme === '' ? '' : "{$this->scheme}:";

    $authority = $this->getAuthority();
    if ($authority !== '') {
      $uri .= "//{$authority}";
    }

    $path = $this->path;
    if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
      // authority があるときのパスは / で始まる必要がある
      $path = "/{$path}";
    } elseif ($authority === '' && str_starts_with($path, '//')) {
      // authority がないのに // で始まると authority と読まれてしまう
      $path = '/' . ltrim($path, '/');
    }
    $uri .= $path;

    if ($this->query !== '') {
      $uri .= "?{$this->query}";
    }
    if ($this->fragment !== '') {
      $uri .= "#{$this->fragment}";
    }

    return $uri;
  }

  /**
   * スキームの既定ポートは null として扱う
   */
  private function filterPort(?int $port): ?int
  {
    if ($port === null) {
      return null;
    }
    if ($port < 0 || $port > 65535) {
      throw new InvalidArgumentException("Invalid port: {$port}. Must be between 0 and 65535");
    }

    return (self::DEFAULT_PORTS[$this->scheme] ?? null) === $port ? null : $port;
  }

  private static function encode(string $pattern, string $value): string
  {
    return (string) preg_replace_callback(
      $pattern,
      fn (array $matches): string => rawurlencode($matches[0]),
      $value,
    );
  }
}
