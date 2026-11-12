<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Request implements RequestInterface
{
  use RequestTrait;

  /**
   * @param array<string, string|list<string>> $headers
   */
  public function __construct(
    string $method,
    UriInterface|string $uri,
    array $headers = [],
    StreamInterface|string|null $body = null,
    string $version = '1.1',
  ) {
    $this->initialize($method, $uri, $headers, $body, $version);
  }
}
