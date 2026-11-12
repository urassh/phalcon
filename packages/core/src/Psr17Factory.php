<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class Psr17Factory implements
  ResponseFactoryInterface,
  ServerRequestFactoryInterface,
  StreamFactoryInterface,
  UriFactoryInterface
{
  public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
  {
    return new Response($code, reasonPhrase: $reasonPhrase);
  }

  /**
   * クエリパラメータは URI のクエリ文字列から埋める
   *
   * @param mixed $uri 実行時に検証する
   * @param array<mixed> $serverParams
   */
  public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
  {
    if (!is_string($uri) && !$uri instanceof UriInterface) {
      throw new InvalidArgumentException('URI must be a string or ' . UriInterface::class);
    }

    $request = new ServerRequest($method, $uri, serverParams: $serverParams);
    parse_str($request->getUri()->getQuery(), $query);

    return $request->withQueryParams($query);
  }

  public function createStream(string $content = ''): StreamInterface
  {
    return new Stream($content);
  }

  /**
   * ファイルの中身を読み込んだインメモリのストリームを返す。書き込んでもファイルには反映されない
   */
  public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
  {
    if ($mode === '' || !in_array($mode[0], ['r', 'w', 'a', 'x', 'c'], true)) {
      throw new InvalidArgumentException("Invalid mode: {$mode}");
    }

    $resource = @fopen($filename, $mode);
    if ($resource === false) {
      throw new RuntimeException("Unable to open {$filename}");
    }

    try {
      return $this->createStreamFromResource($resource);
    } finally {
      fclose($resource);
    }
  }

  /**
   * リソースの残りを読み込んだインメモリのストリームを返す
   */
  public function createStreamFromResource($resource): StreamInterface
  {
    if (!is_resource($resource)) {
      throw new InvalidArgumentException('Expected a resource, got ' . get_debug_type($resource));
    }

    $contents = @stream_get_contents($resource);

    return new Stream($contents === false ? '' : $contents);
  }

  public function createUri(string $uri = ''): UriInterface
  {
    return new Uri($uri);
  }
}
