<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use InvalidArgumentException;
use JsonException;
use Urassh\Phalcon\Internal\Http\BadRequestException;
use Urassh\Phalcon\Internal\Http\Headers;

final class Request
{
  /** @internal */
  public const HEADER_TERMINATOR = "\r\n\r\n";

  private const LINE_TERMINATOR = "\r\n";

  /**
   * @internal
   *
   * @param array<string, string> $params ルートパラメータ
   */
  public function __construct(
    private readonly string $method,
    private readonly string $target,
    private readonly string $version,
    private readonly Headers $headers,
    private readonly string $body,
    private readonly array $params = [],
  ) {
  }

  /**
   * @internal
   *
   * @throws InvalidArgumentException リクエストラインが不正な場合
   */
  public static function parse(string $data): self
  {
    $headerEnd = strpos($data, self::HEADER_TERMINATOR);
    $head = $headerEnd === false ? $data : substr($data, 0, $headerEnd);
    $rest = $headerEnd === false ? '' : substr($data, $headerEnd + strlen(self::HEADER_TERMINATOR));

    [$requestLine, $headerLines] = self::splitHead($head);

    $parts = explode(' ', $requestLine);
    if (count($parts) !== 3 || in_array('', $parts, true)) {
      throw new InvalidArgumentException("Malformed request line: {$requestLine}");
    }
    [$method, $target, $version] = $parts;

    $headers = Headers::parse($headerLines);

    return new self($method, $target, $version, $headers, substr($rest, 0, $headers->contentLength()));
  }

  /**
   * ヘッダ部をリクエストラインとヘッダフィールドの行に分ける
   *
   * @internal
   *
   * @return array{string, string}
   */
  public static function splitHead(string $head): array
  {
    $lineEnd = strpos($head, self::LINE_TERMINATOR);
    if ($lineEnd === false) {
      return [$head, ''];
    }

    return [substr($head, 0, $lineEnd), substr($head, $lineEnd + strlen(self::LINE_TERMINATOR))];
  }

  /**
   * ルートパラメータを差し替えた新しいインスタンスを返す
   *
   * @internal
   *
   * @param array<string, string> $params
   */
  public function withParams(array $params): self
  {
    return new self($this->method, $this->target, $this->version, $this->headers, $this->body, $params);
  }

  public function method(): string
  {
    return $this->method;
  }

  public function target(): string
  {
    return $this->target;
  }

  public function version(): string
  {
    return $this->version;
  }

  /**
   * リクエストターゲットのパス部分 (クエリ文字列を除く)
   */
  public function path(): string
  {
    if (str_starts_with($this->target, '/')) {
      $pos = strpos($this->target, '?');

      return $pos === false ? $this->target : substr($this->target, 0, $pos);
    }

    // absolute-form (http://host/path) は parse_url に任せる
    $path = parse_url($this->target, PHP_URL_PATH);

    return is_string($path) && $path !== '' ? $path : '/';
  }

  public function param(string $name): ?string
  {
    return $this->params[$name] ?? null;
  }

  public function query(string $name): ?string
  {
    $query = parse_url($this->target, PHP_URL_QUERY);
    if (!is_string($query)) {
      return null;
    }

    parse_str($query, $values);
    $value = $values[$name] ?? null;

    return is_string($value) ? $value : null;
  }

  public function header(string $name): ?string
  {
    return $this->headers->get($name);
  }

  /** @internal */
  public function headers(): Headers
  {
    return $this->headers;
  }

  public function body(): string
  {
    return $this->body;
  }

  /**
   * ボディを JSON としてデコードする。オブジェクトは連想配列になる
   *
   * 不正な JSON の場合はハンドラの処理を打ち切り、400 Bad Request を返す
   */
  public function json(): mixed
  {
    try {
      return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new BadRequestException('Malformed JSON body', previous: $e);
    }
  }
}
