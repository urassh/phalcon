<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Request / ServerRequest に共通する RequestInterface の実装
 *
 * @internal
 */
trait RequestTrait
{
  use MessageTrait;

  private const METHOD_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+\-.^_`|~]+\z/';

  private string $method;
  private ?string $requestTarget = null;
  private UriInterface $uri;

  /**
   * @param array<mixed> $headers
   */
  private function initialize(
    string $method,
    UriInterface|string $uri,
    array $headers,
    StreamInterface|string|null $body,
    string $version,
  ): void {
    $this->method = self::validateMethod($method);
    $this->uri = is_string($uri) ? new Uri($uri) : $uri;
    $this->setHeaders($headers);
    $this->body = $body instanceof StreamInterface ? $body : new Stream($body ?? '');
    $this->protocolVersion = $version;

    if (!$this->hasHeader('Host')) {
      $this->updateHostFromUri();
    }
  }

  public function getRequestTarget(): string
  {
    if ($this->requestTarget !== null) {
      return $this->requestTarget;
    }

    $target = $this->uri->getPath();
    if ($target === '') {
      $target = '/';
    }
    $query = $this->uri->getQuery();

    return $query === '' ? $target : "{$target}?{$query}";
  }

  public function withRequestTarget(string $requestTarget): RequestInterface
  {
    if (preg_match('/\s/', $requestTarget) === 1) {
      throw new InvalidArgumentException('Request target must not contain whitespace');
    }

    $new = clone $this;
    $new->requestTarget = $requestTarget;

    return $new;
  }

  public function getMethod(): string
  {
    return $this->method;
  }

  public function withMethod(string $method): RequestInterface
  {
    $new = clone $this;
    $new->method = self::validateMethod($method);

    return $new;
  }

  public function getUri(): UriInterface
  {
    return $this->uri;
  }

  public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
  {
    $new = clone $this;
    $new->uri = $uri;

    if (!$preserveHost || !$this->hasHeader('Host')) {
      $new->updateHostFromUri();
    }

    return $new;
  }

  private function updateHostFromUri(): void
  {
    $host = $this->uri->getHost();
    if ($host === '') {
      return;
    }

    $port = $this->uri->getPort();
    if ($port !== null) {
      $host .= ":{$port}";
    }

    // Host は先頭に置く
    $this->removeHeader('Host');
    $this->headerNames = ['host' => 'Host'] + $this->headerNames;
    $this->headers = ['Host' => [$host]] + $this->headers;
  }

  private static function validateMethod(string $method): string
  {
    if (preg_match(self::METHOD_PATTERN, $method) !== 1) {
      throw new InvalidArgumentException("Invalid HTTP method: {$method}");
    }

    return $method;
  }
}
