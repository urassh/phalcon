<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Exception\ForbiddenException;

/**
 * 別のオリジンからブラウザに送らせた、安全でないメソッドのリクエスト (CSRF) を 403 にする
 *
 * トークンは使わず、ブラウザが付ける Sec-Fetch-Site を見る。Sec-Fetch-Site がなければ Origin と Host を比べ、
 * どちらもなければブラウザ以外のクライアントとみなして通す。Go の net/http.CrossOriginProtection と同じ判定
 *
 * @internal
 */
final class CrossOriginProtection implements MiddlewareInterface
{
  private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

  /**
   * @param list<string> $trustedOrigins 別オリジンでも通す `https://app.example.com` 形式のオリジン。小文字で渡す
   */
  public function __construct(
    private readonly array $trustedOrigins = [],
  ) {
  }

  /**
   * @throws ForbiddenException 別オリジンからのリクエストの場合
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    if (!in_array($request->getMethod(), self::SAFE_METHODS, true) && !$this->allows($request)) {
      throw new ForbiddenException('Cross-origin request blocked');
    }

    return $handler->handle($request);
  }

  private function allows(ServerRequestInterface $request): bool
  {
    $origin = strtolower($request->getHeaderLine('Origin'));
    if ($origin !== '' && in_array($origin, $this->trustedOrigins, true)) {
      return true;
    }

    // same-site は別のサブドメインからでも付くので、cross-site と同じく拒否する
    $site = $request->getHeaderLine('Sec-Fetch-Site');
    if ($site !== '') {
      return $site === 'same-origin' || $site === 'none';
    }

    // Sec-Fetch-Site は HTTPS か localhost でしか付かないので、HTTP の開発環境や古いブラウザでは Origin で判定する。
    // どちらもなければ curl などブラウザ以外のクライアントとみなす
    if ($origin === '') {
      return true;
    }

    // `Origin: null` (サンドボックスの iframe など) は host がなく、ここで拒否される
    $parts = parse_url($origin);
    if ($parts === false || !isset($parts['host'])) {
      return false;
    }
    $host = isset($parts['port']) ? "{$parts['host']}:{$parts['port']}" : $parts['host'];

    return $host === strtolower($request->getHeaderLine('Host'));
  }
}
