<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Urassh\Phalcon\Internal\Http\BadRequestException;

/**
 * ハンドラが受け取るリクエスト。PSR-7 の ServerRequestInterface を包む
 */
final class Request
{
  /**
   * @internal
   */
  public function __construct(
    private readonly ServerRequestInterface $request,
  ) {
  }

  /**
   * ルートパラメータをリクエストの属性に加えた新しいインスタンスを返す
   *
   * @internal
   *
   * @param array<string, string> $params
   */
  public function withParams(array $params): self
  {
    $request = $this->request;
    foreach ($params as $name => $value) {
      $request = $request->withAttribute($name, $value);
    }

    return new self($request);
  }

  /**
   * 包んでいる PSR-7 のリクエスト。ミドルウェアが付けた属性などを読むとき用
   */
  public function psr(): ServerRequestInterface
  {
    return $this->request;
  }

  public function method(): string
  {
    return $this->request->getMethod();
  }

  public function target(): string
  {
    return $this->request->getRequestTarget();
  }

  public function version(): string
  {
    return "HTTP/{$this->request->getProtocolVersion()}";
  }

  /**
   * リクエストターゲットのパス部分 (クエリ文字列を除く)
   */
  public function path(): string
  {
    $path = $this->request->getUri()->getPath();

    return $path === '' ? '/' : $path;
  }

  public function param(string $name): ?string
  {
    $value = $this->request->getAttribute($name);

    return is_string($value) ? $value : null;
  }

  public function query(string $name): ?string
  {
    $value = $this->request->getQueryParams()[$name] ?? null;

    return is_string($value) ? $value : null;
  }

  public function header(string $name): ?string
  {
    return $this->request->getHeader($name)[0] ?? null;
  }

  public function body(): string
  {
    return (string) $this->request->getBody();
  }

  /**
   * ボディを JSON としてデコードする。オブジェクトは連想配列になる
   *
   * 不正な JSON の場合はハンドラの処理を打ち切り、400 Bad Request を返す
   */
  public function json(): mixed
  {
    try {
      return json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new BadRequestException('Malformed JSON body', previous: $e);
    }
  }
}
