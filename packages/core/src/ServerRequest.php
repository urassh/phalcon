<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

final class ServerRequest implements ServerRequestInterface
{
  use RequestTrait;

  /** @var array<mixed> */
  private array $cookieParams = [];

  /** @var array<mixed> */
  private array $queryParams = [];

  /** @var array<mixed> */
  private array $uploadedFiles = [];

  /** @var array<mixed>|object|null */
  private array|object|null $parsedBody = null;

  /** @var array<string, mixed> */
  private array $attributes = [];

  /**
   * @param array<string, string|list<string>> $headers
   * @param array<mixed> $serverParams
   */
  public function __construct(
    string $method,
    UriInterface|string $uri,
    array $headers = [],
    StreamInterface|string|null $body = null,
    string $version = '1.1',
    private readonly array $serverParams = [],
  ) {
    $this->initialize($method, $uri, $headers, $body, $version);
  }

  /**
   * @return array<mixed>
   */
  public function getServerParams(): array
  {
    return $this->serverParams;
  }

  /**
   * @return array<mixed>
   */
  public function getCookieParams(): array
  {
    return $this->cookieParams;
  }

  /**
   * @param array<mixed> $cookies
   */
  public function withCookieParams(array $cookies): ServerRequestInterface
  {
    $new = clone $this;
    $new->cookieParams = $cookies;

    return $new;
  }

  /**
   * @return array<mixed>
   */
  public function getQueryParams(): array
  {
    return $this->queryParams;
  }

  /**
   * @param array<mixed> $query
   */
  public function withQueryParams(array $query): ServerRequestInterface
  {
    $new = clone $this;
    $new->queryParams = $query;

    return $new;
  }

  /**
   * @return array<mixed>
   */
  public function getUploadedFiles(): array
  {
    return $this->uploadedFiles;
  }

  /**
   * @param array<mixed> $uploadedFiles
   */
  public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
  {
    self::validateUploadedFiles($uploadedFiles);

    $new = clone $this;
    $new->uploadedFiles = $uploadedFiles;

    return $new;
  }

  /**
   * @return array<mixed>|object|null
   */
  public function getParsedBody()
  {
    return $this->parsedBody;
  }

  /**
   * @param mixed $data 実行時に検証する
   */
  public function withParsedBody($data): ServerRequestInterface
  {
    if (!is_array($data) && !is_object($data) && $data !== null) {
      throw new InvalidArgumentException('Parsed body must be an array, an object or null, got ' . get_debug_type($data));
    }

    $new = clone $this;
    $new->parsedBody = $data;

    return $new;
  }

  /**
   * @return array<string, mixed>
   */
  public function getAttributes(): array
  {
    return $this->attributes;
  }

  public function getAttribute(string $name, $default = null)
  {
    return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
  }

  public function withAttribute(string $name, $value): ServerRequestInterface
  {
    $new = clone $this;
    $new->attributes[$name] = $value;

    return $new;
  }

  public function withoutAttribute(string $name): ServerRequestInterface
  {
    $new = clone $this;
    unset($new->attributes[$name]);

    return $new;
  }

  /**
   * 葉が UploadedFileInterface の木になっているか確かめる
   *
   * @param array<mixed> $files
   */
  private static function validateUploadedFiles(array $files): void
  {
    foreach ($files as $file) {
      if (is_array($file)) {
        self::validateUploadedFiles($file);
      } elseif (!$file instanceof UploadedFileInterface) {
        throw new InvalidArgumentException('Uploaded files must be instances of ' . UploadedFileInterface::class);
      }
    }
  }
}
