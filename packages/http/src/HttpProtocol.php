<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Server\Protocol;

/**
 * 受け取ったバイト列を ServerRequestInterface にして PSR-15 のハンドラに渡す
 */
final class HttpProtocol implements Protocol
{
  private readonly RequestParser $parser;

  public function __construct(
    private readonly RequestHandlerInterface $handler,
  ) {
    $this->parser = new RequestParser();
  }

  public function isComplete(string $data): bool
  {
    return RequestParser::isComplete($data);
  }

  public function respond(string $data): string
  {
    try {
      $request = $this->parser->parse($data);
    } catch (InvalidArgumentException) {
      return ResponseSerializer::serialize(self::text(400, "Bad Request\n"));
    }

    // 任意のハンドラを受け付けるので、例外で接続ごと落ちないようここでも捕まえる
    try {
      $response = $this->handler->handle($request);
    } catch (Throwable $e) {
      error_log("Unhandled exception in {$request->getMethod()} {$request->getRequestTarget()}: {$e}");

      return ResponseSerializer::serialize(self::text(500, "Internal Server Error\n"));
    }

    return ResponseSerializer::serialize($response);
  }

  private static function text(int $status, string $body): Response
  {
    return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
  }
}
