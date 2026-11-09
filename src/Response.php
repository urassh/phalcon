<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use LogicException;

final class Response
{
  private const REASON_PHRASES = [
    200 => 'OK',
    201 => 'Created',
    204 => 'No Content',
    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    409 => 'Conflict',
    422 => 'Unprocessable Content',
    500 => 'Internal Server Error',
  ];

  /** サーバが自動で付けるため、利用者の指定は無視するヘッダ */
  private const MANAGED_HEADERS = ['content-length', 'connection'];

  /**
   * @param array<string, array{string, string}> $headers 小文字化したヘッダ名 => [元の名前, 値]
   * @param array{string, array<string, mixed>}|null $view 未描画のテンプレート [名前, データ]
   */
  private function __construct(
    private readonly string $body,
    private readonly int $status,
    private readonly array $headers,
    private readonly ?array $view = null,
  ) {
  }

  public static function text(string $body, int $status = 200): self
  {
    return self::withContentType($body, $status, 'text/plain; charset=utf-8');
  }

  public static function html(string $body, int $status = 200): self
  {
    return self::withContentType($body, $status, 'text/html; charset=utf-8');
  }

  /**
   * @throws \JsonException JSON にエンコードできない場合
   */
  public static function json(mixed $data, int $status = 200): self
  {
    $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return self::withContentType($body, $status, 'application/json');
  }

  /**
   * テンプレート $name を描画した結果を HTML として返す
   *
   * 描画はハンドラが返したあと、App に設定された Renderer で行う
   *
   * @param array<string, mixed> $data テンプレート内で変数になる
   */
  public static function view(string $name, array $data = [], int $status = 200): self
  {
    return (new self('', $status, [], [$name, $data]))
      ->withHeader('Content-Type', 'text/html; charset=utf-8');
  }

  private static function withContentType(string $body, int $status, string $contentType): self
  {
    return (new self($body, $status, []))->withHeader('Content-Type', $contentType);
  }

  public function withStatus(int $status): self
  {
    return new self($this->body, $status, $this->headers, $this->view);
  }

  public function withHeader(string $name, string $value): self
  {
    $headers = $this->headers;
    $headers[strtolower($name)] = [$name, $value];

    return new self($this->body, $this->status, $headers, $this->view);
  }

  /** @internal */
  public function needsRender(): bool
  {
    return $this->view !== null;
  }

  /**
   * テンプレートを描画し、その結果をボディにした新しいインスタンスを返す
   *
   * @internal
   */
  public function render(Renderer $renderer): self
  {
    if ($this->view === null) {
      return $this;
    }

    [$name, $data] = $this->view;

    return new self($renderer->render($name, $data), $this->status, $this->headers);
  }

  /** @internal */
  public function status(): int
  {
    return $this->status;
  }

  /** @internal */
  public function header(string $name): ?string
  {
    return $this->headers[strtolower($name)][1] ?? null;
  }

  /** @internal */
  public function body(): string
  {
    return $this->body;
  }

  /** @internal */
  public function toString(): string
  {
    if ($this->view !== null) {
      throw new LogicException("View '{$this->view[0]}' has not been rendered");
    }

    $reason = self::REASON_PHRASES[$this->status] ?? '';

    $head = "HTTP/1.1 {$this->status} {$reason}\r\n";
    foreach ($this->headers as $key => [$name, $value]) {
      if (in_array($key, self::MANAGED_HEADERS, true)) {
        continue;
      }
      $head .= "{$name}: {$value}\r\n";
    }

    return $head
      . 'Content-Length: ' . strlen($this->body) . "\r\n"
      . "Connection: close\r\n"
      . "\r\n"
      . $this->body;
  }
}
