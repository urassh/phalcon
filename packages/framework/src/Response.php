<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class Response
{
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
   * 描画はハンドラが返したあと、Phalcon に設定された Renderer で行う
   *
   * @param array<string, mixed> $data テンプレート内で変数になる
   */
  public static function view(string $name, array $data = [], int $status = 200): self
  {
    return (new self('', $status, [], [$name, $data]))
      ->withHeader('Content-Type', 'text/html; charset=utf-8');
  }

  /**
   * $location へリダイレクトする。ボディは空
   *
   * フォームの POST のあとに GET で戻す場合は seeOther()、メソッドとボディを保ったまま転送する場合は 307 / 308 を指定する
   */
  public static function redirect(string $location, int $status = 302): self
  {
    return (new self('', $status, []))->withHeader('Location', $location);
  }

  /**
   * 303 See Other で $location へリダイレクトする。フォームの POST のあとに一覧や詳細へ GET で戻すときに使う
   */
  public static function seeOther(string $location): self
  {
    return self::redirect($location, 303);
  }

  /**
   * Referer のページへ戻る。Referer がない、またはほかのサイトを指している場合は $fallback へ戻す
   *
   * 戻り先にはパスとクエリ文字列だけを使い、外部のサイトへは飛ばさない (オープンリダイレクトを防ぐ)
   */
  public static function back(Request $request, string $fallback = '/', int $status = 302): self
  {
    return self::redirect(self::sameOriginPath($request) ?? $fallback, $status);
  }

  /**
   * 204 No Content。ボディは空で、Content-Type も付けない
   */
  public static function noContent(): self
  {
    return new self('', 204, []);
  }

  /**
   * Referer が同じホストを指していれば、そのパスとクエリ文字列を返す。それ以外は null
   */
  private static function sameOriginPath(Request $request): ?string
  {
    $referer = $request->header('Referer');
    if ($referer === null) {
      return null;
    }

    $parts = parse_url($referer);
    if ($parts === false) {
      return null;
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
      $scheme = strtolower($parts['scheme'] ?? '');
      $host = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ":{$parts['port']}" : ''));
      if (!in_array($scheme, ['http', 'https'], true) || $host !== strtolower((string) $request->header('Host'))) {
        return null;
      }
    }

    $path = $parts['path'] ?? '/';
    // `//evil.com` や `/\evil.com` はブラウザが別のホストとして解釈する
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
      return null;
    }

    return isset($parts['query']) ? "{$path}?{$parts['query']}" : $path;
  }

  private static function withContentType(string $body, int $status, string $contentType): self
  {
    return (new self($body, $status, []))->withHeader('Content-Type', $contentType);
  }

  public function withStatus(int $status): self
  {
    return new self($this->body, $status, $this->headers, $this->view);
  }

  /**
   * @throws InvalidArgumentException ヘッダ名がトークンでない、または値に改行や NUL を含む場合
   */
  public function withHeader(string $name, string $value): self
  {
    // ヘッダインジェクションを防ぐ。描画前のこの時点で弾けば、ハンドラの例外として 500 になる
    if (preg_match('/\A[A-Za-z0-9!#$%&\'*+\-.^_`|~]+\z/', $name) !== 1) {
      throw new InvalidArgumentException("Invalid header name: {$name}");
    }
    if (strpbrk($value, "\r\n\0") !== false) {
      throw new InvalidArgumentException("Header value must not contain CR, LF or NUL: {$name}");
    }

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

  /**
   * PSR-7 のレスポンスに変換する。View は先に render() で描画しておく
   *
   * @internal
   */
  public function toPsr(ResponseFactoryInterface $factory): ResponseInterface
  {
    if ($this->view !== null) {
      throw new LogicException("View '{$this->view[0]}' has not been rendered");
    }

    $response = $factory->createResponse($this->status);
    foreach ($this->headers as [$name, $value]) {
      $response = $response->withHeader($name, $value);
    }
    $response->getBody()->write($this->body);

    return $response;
  }
}
