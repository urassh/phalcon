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
   * PHP-FPM などの SAPI が用意したスーパーグローバルからリクエストを作る
   *
   * 引数を省略した値はスーパーグローバルと php://input から読む。
   * parsedBody は、PHP が $_POST を埋める POST のフォーム (application/x-www-form-urlencoded / multipart/form-data) のときだけ入れる。
   * アップロードされたファイル ($_FILES) は読まない
   *
   * @param array<mixed>|null $server 省略すると $_SERVER
   * @param array<mixed>|null $query 省略すると $_GET
   * @param array<mixed>|null $post 省略すると $_POST
   * @param array<mixed>|null $cookies 省略すると $_COOKIE
   * @param string|null $body 省略すると php://input
   * @throws InvalidArgumentException メソッドや Host、ヘッダが不正な場合
   */
  public static function fromGlobals(
    ?array $server = null,
    ?array $query = null,
    ?array $post = null,
    ?array $cookies = null,
    ?string $body = null,
  ): self {
    $server ??= $_SERVER;
    $method = self::serverString($server, 'REQUEST_METHOD') ?? 'GET';
    $protocol = self::serverString($server, 'SERVER_PROTOCOL') ?? '';
    $version = preg_match('#\AHTTP/(\d+(?:\.\d+)?)\z#', $protocol, $matches) === 1 ? $matches[1] : '1.1';

    $request = new self(
      $method,
      self::uriFromServer($server),
      self::headersFromServer($server),
      $body ?? (string) file_get_contents('php://input'),
      $version,
      $server,
    );
    $request = $request->withQueryParams($query ?? $_GET)->withCookieParams($cookies ?? $_COOKIE);

    $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
    if ($method === 'POST' && in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
      $request = $request->withParsedBody($post ?? $_POST);
    }

    return $request;
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
   * Host ヘッダ (なければ SERVER_NAME と SERVER_PORT) と REQUEST_URI から URI を組み立てる
   *
   * @param array<mixed> $server
   */
  private static function uriFromServer(array $server): string
  {
    $target = self::serverString($server, 'REQUEST_URI');
    if ($target === null) {
      $queryString = self::serverString($server, 'QUERY_STRING') ?? '';
      $target = $queryString === '' ? '/' : "/?{$queryString}";
    }

    $https = self::serverString($server, 'HTTPS') ?? '';
    $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';

    $host = self::serverString($server, 'HTTP_HOST');
    if ($host === null) {
      $host = self::serverString($server, 'SERVER_NAME');
      $port = self::serverString($server, 'SERVER_PORT');
      if ($host !== null && $port !== null && $port !== ($scheme === 'https' ? '443' : '80')) {
        $host .= ":{$port}";
      }
    }

    return $host === null || $host === '' ? $target : "{$scheme}://{$host}{$target}";
  }

  /**
   * HTTP_* と CONTENT_TYPE / CONTENT_LENGTH をヘッダに戻す。HTTP_ACCEPT_LANGUAGE は Accept-Language になる
   *
   * @param array<mixed> $server
   * @return array<string, string>
   */
  private static function headersFromServer(array $server): array
  {
    $headers = [];
    foreach ($server as $key => $value) {
      if (!is_string($key) || !is_string($value)) {
        continue;
      }

      if (str_starts_with($key, 'HTTP_')) {
        $name = substr($key, 5);
      } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) && $value !== '') {
        // FastCGI では本文がなくても空文字で渡ってくる
        $name = $key;
      } else {
        continue;
      }

      $headers[ucwords(strtolower(str_replace('_', '-', $name)), '-')] = $value;
    }

    return $headers;
  }

  /**
   * @param array<mixed> $server
   */
  private static function serverString(array $server, string $key): ?string
  {
    return isset($server[$key]) && is_string($server[$key]) ? $server[$key] : null;
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
